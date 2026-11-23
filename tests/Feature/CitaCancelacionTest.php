<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\CitaCancelacionPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CitaCancelacionTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $db = DB::connection();
        if (! $this->app->environment('testing') || $this->app->configurationIsCached()
            || $db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:' || ! empty($db->getConfig('url'))) {
            throw new LogicException('Se requiere SQLite en memoria sin caché de configuración.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-22 07:00:00', 'America/Bogota'));
    }

    private function actor(array $codigos = ['RF-027'], array $atributos = []): User
    {
        $rol = Rol::query()->create(['nombre' => 'Agenda ficticia '.uniqid()]);
        foreach ($codigos as $codigo) {
            $rol->permisos()->attach(Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $codigo]));
        }

        return User::factory()->for($rol)->create($atributos);
    }

    /** @return array<string, mixed> */
    private function confirmar(Cita $cita, array $cambios = []): array
    {
        return [
            'version_horario' => $cita->versionHorario(), 'confirmacion' => '1',
            'motivo_cancelacion' => 'Solicitud ficticia del paciente', ...$cambios,
        ];
    }

    /** @return array<string, mixed> */
    private function agendarMismoHorario(Cita $cita): array
    {
        return [
            'paciente_id' => $cita->paciente_id, 'medico_id' => $cita->medico_id,
            'fecha' => $cita->fecha->toDateString(), 'hora_inicio' => substr($cita->hora_inicio, 0, 5),
            'motivo_consulta' => 'Nueva reserva ficticia', 'confirmacion' => '1',
        ];
    }

    public function test_ca02701_preserva_cita_y_libera_horario_aunque_el_paciente_este_inactivo(): void
    {
        $cita = Cita::factory()->create();
        $cita->paciente->forceFill(['estado' => 'INACTIVO'])->save();
        $pacienteAntes = $cita->paciente->getAttributes();
        $url = route('citas.cancelar.show', $cita);
        $actor = $this->actor();
        $antes = $cita->refresh()->getAttributes();
        $datos = $this->confirmar($cita);

        $this->actingAs($actor)->get(route('citas.cancelar.index'))->assertOk()->assertSee('ID de la cita');
        $this->get(route('citas.cancelar.index', ['cita_id' => $cita->id]))->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Confirmar cancelación')->assertSee($cita->versionHorario());
        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);

        $this->patch(route('citas.cancelar.update', $cita), $datos)
            ->assertRedirect($url)->assertSessionHas('status', 'Consulta cancelada correctamente.');
        $this->get($url)->assertOk()->assertSee('CANCELADA')->assertSee($datos['motivo_cancelacion'])
            ->assertDontSee('Confirmar cancelación');

        $guardada = Cita::query()->sole();
        $this->assertSame('CANCELADA', $guardada->estado);
        $this->assertSame($datos['motivo_cancelacion'], $guardada->motivo_cancelacion);
        $this->assertNull($guardada->inicio_ocupado);
        foreach (['id', 'medico_id', 'paciente_id', 'created_by', 'disponibilidad_medica_id', 'modalidad', 'tipo_paciente_al_agendar', 'motivo_consulta', 'valor_base'] as $campo) {
            $this->assertSame($cita->{$campo}, $guardada->{$campo});
        }
        $this->assertSame($cita->horario(), $guardada->horario());
        $this->assertSame($pacienteAntes, $cita->paciente->refresh()->getAttributes());
        $this->assertContains('08:00', array_column($guardada->medico->intervalosLibres($guardada->fecha->toDateString()), 'inicio'));

        $auditoria = Auditoria::query()->sole();
        $this->assertSame($actor->id, $auditoria->user_id);
        $this->assertSame($cita->id, $auditoria->registro_id);
        $this->assertSame('CITA_CANCELADA', $auditoria->accion);
        $this->assertSame('Cita', $auditoria->entidad);
        $this->assertSame('EXITO', $auditoria->resultado);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $auditoria->getRawOriginal('fecha_hora'));
        $this->assertStringContainsString($guardada->horario(), $auditoria->descripcion);
        $this->assertStringNotContainsString($datos['motivo_cancelacion'], $auditoria->descripcion);
    }

    public function test_rf024_puede_reservar_el_intervalo_de_una_particular_cancelada(): void
    {
        $cita = Cita::factory()->create();
        $datos = [...$this->agendarMismoHorario($cita), 'valor_base' => '40000'];
        $this->actingAs($this->actor(['RF-024', 'RF-027']))->postJson(route('citas.store'), $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');

        $this->patch(route('citas.cancelar.update', $cita), $this->confirmar($cita))->assertSessionHasNoErrors();
        $this->post(route('citas.store'), $datos)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('citas', 2);
        $this->assertSame('CANCELADA', $cita->refresh()->estado);
        $nueva = $cita->medico->citas()->ocupaHorario()->sole();
        $this->assertNotSame($cita->id, $nueva->id);
        $this->assertSame($cita->horario(), $nueva->horario());
    }

    public function test_ca02702_rf025_puede_reutilizar_remision_vigente_e_intervalo(): void
    {
        $remision = RemisionEps::factory()->create(['vigencia_hasta' => '2026-11-22']);
        $cita = Cita::factory()->for($remision->paciente)->for($remision, 'remision')->create([
            'modalidad' => 'EPS', 'valor_base' => null,
        ]);
        $datos = [...$this->agendarMismoHorario($cita), 'remision_id' => $remision->id];
        $remisionAntes = $remision->refresh()->getAttributes();

        $this->actingAs($this->actor(['RF-025', 'RF-027']))->postJson(route('citas.eps.store'), $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('remision_id');
        $this->patch(route('citas.cancelar.update', $cita), $this->confirmar($cita))->assertSessionHasNoErrors();
        $this->assertNull($cita->refresh()->remision_reservada);
        $this->post(route('citas.eps.store'), $datos)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('CANCELADA', $cita->refresh()->estado);
        $this->assertSame($remision->id, $cita->remision_id);
        $this->assertSame($remisionAntes, $remision->refresh()->getAttributes());
        $this->assertDatabaseCount('cambios_estado_remision', 0);
        $this->assertSame(2, $remision->citas()->count());
        $nueva = $remision->citas()->ocupaHorario()->sole();
        $this->assertNotSame($cita->id, $nueva->id);
        $this->assertSame($cita->horario(), $nueva->horario());
        $this->assertSame($cita->medico_id, $nueva->medico_id);
    }

    public static function remisionesNoAgendables(): array
    {
        return ['vigencia vencida' => ['VIGENTE'], 'usada' => ['USADA'], 'anulada' => ['ANULADA']];
    }

    #[DataProvider('remisionesNoAgendables')]
    public function test_liberar_reserva_al_cancelar_no_habilita_remision_invalida(string $estado): void
    {
        $remision = RemisionEps::factory()->create([
            'estado' => $estado, 'fecha_remision' => '2026-11-20', 'vigencia_hasta' => $estado === 'VIGENTE' ? '2026-11-21' : '2026-11-23',
        ]);
        $cita = Cita::factory()->for($remision->paciente)->for($remision, 'remision')->create([
            'modalidad' => 'EPS', 'valor_base' => null,
        ]);
        $remisionAntes = $remision->refresh()->getAttributes();
        $this->actingAs($this->actor(['RF-027', 'RF-025']))
            ->patch(route('citas.cancelar.update', $cita), $this->confirmar($cita))->assertSessionHasNoErrors();
        $this->postJson(route('citas.eps.store'), [...$this->agendarMismoHorario($cita), 'remision_id' => $remision->id])
            ->assertUnprocessable()->assertJsonValidationErrors('remision_id');
        $this->assertSame('CANCELADA', $cita->refresh()->estado);
        $this->assertSame($remisionAntes, $remision->refresh()->getAttributes());
        $this->assertDatabaseCount('citas', 1);
    }

    public static function estadosConAtencion(): array
    {
        return [['EN_ATENCION'], ['ATENDIDA']];
    }

    #[DataProvider('estadosConAtencion')]
    public function test_ca02703_rechaza_cancelar_una_cita_con_atencion(string $estado): void
    {
        $cita = Cita::factory()->create(['estado' => $estado]);
        $antes = $cita->refresh()->getAttributes();
        $this->actingAs($this->actor())->get(route('citas.cancelar.show', $cita))
            ->assertOk()->assertDontSee('Confirmar cancelación');
        $this->patchJson(route('citas.cancelar.update', $cita), $this->confirmar($cita))
            ->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_ca02704_rechaza_solicitudes_sin_permiso_o_con_entradas_invalidas(): void
    {
        $cita = Cita::factory()->create();
        $url = route('citas.cancelar.update', $cita);
        $antes = $cita->refresh()->getAttributes();

        $this->patchJson($url, $this->confirmar($cita))->assertUnauthorized();
        $this->actingAs($this->actor(['RF-024', 'RF-025', 'RF-026']));
        $this->get(route('citas.cancelar.index'))->assertForbidden();
        $this->get(route('citas.cancelar.show', $cita))->assertForbidden()->assertDontSee($cita->paciente->nombres);
        $this->get(route('citas.cancelar.show', 99999999))->assertForbidden();
        $this->patchJson($url, $this->confirmar($cita))->assertForbidden();
        $this->actingAs($this->actor(['RF-027'], ['estado' => 'inactivo']))
            ->patchJson($url, $this->confirmar($cita))->assertRedirect(route('login'));

        $this->actingAs($this->actor())->patchJson($url, $this->confirmar($cita, [
            'motivo_cancelacion' => '   ', 'confirmacion' => '0', 'estado' => 'ATENDIDA',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['motivo_cancelacion', 'confirmacion', 'estado']);
        $this->patchJson($url, $this->confirmar($cita, ['motivo_cancelacion' => str_repeat('a', 501)]))
            ->assertUnprocessable()->assertJsonValidationErrors('motivo_cancelacion');

        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_dos_confirmaciones_del_mismo_formulario_no_reemplazan_motivo_ni_auditoria(): void
    {
        $cita = Cita::factory()->create();
        $datos = $this->confirmar($cita);
        $otraConfirmacion = $this->confirmar($cita, ['motivo_cancelacion' => 'Segundo motivo ficticio']);
        $url = route('citas.cancelar.update', $cita);

        $this->actingAs($this->actor())->patch($url, $datos)->assertSessionHasNoErrors();
        $antes = $cita->refresh()->getAttributes();
        $this->actingAs($this->actor())->patch($url, $otraConfirmacion)->assertSessionHas('status', 'La cita ya está cancelada.');

        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertSame($datos['motivo_cancelacion'], $cita->motivo_cancelacion);
        $this->assertDatabaseCount('auditorias', 1);
    }

    public function test_cancelacion_rechaza_una_confirmacion_anterior_a_una_reprogramacion(): void
    {
        $cita = Cita::factory()->create();
        $datos = $this->confirmar($cita);
        $this->actingAs($this->actor(['RF-026', 'RF-027']))->patch(route('citas.reprogramar.update', $cita), [
            'fecha' => $cita->fecha->toDateString(), 'hora_inicio' => '08:30', 'confirmacion' => '1',
            'version_horario' => $cita->versionHorario(),
        ])->assertSessionHasNoErrors();

        $reprogramada = $cita->refresh()->getAttributes();
        $this->patchJson(route('citas.cancelar.update', $cita), $datos)->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->assertSame('08:30:00', $cita->hora_inicio);
        $this->assertSame('PROGRAMADA', $cita->estado);
        $this->assertSame($reprogramada, $cita->refresh()->getAttributes());
        $this->assertSame('CITA_REPROGRAMADA', Auditoria::query()->sole()->accion);
    }

    public function test_rf026_no_reprograma_una_cita_cancelada_despues_de_abrir_el_formulario(): void
    {
        $cita = Cita::factory()->create();
        $version = $cita->versionHorario();
        $this->actingAs($this->actor(['RF-026', 'RF-027']))
            ->patch(route('citas.cancelar.update', $cita), $this->confirmar($cita))->assertSessionHasNoErrors();
        $cancelada = $cita->refresh()->getAttributes();

        $this->patchJson(route('citas.reprogramar.update', $cita), [
            'fecha' => $cita->fecha->toDateString(), 'hora_inicio' => '08:30', 'confirmacion' => '1',
            'version_horario' => $version,
        ])->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');
        $this->assertSame($cancelada, $cita->refresh()->getAttributes());
        $this->assertSame('CITA_CANCELADA', Auditoria::query()->sole()->accion);
    }

    public function test_un_fallo_de_auditoria_revierte_el_motivo_y_conserva_ambas_reservas(): void
    {
        $remision = RemisionEps::factory()->create();
        $cita = Cita::factory()->for($remision->paciente)->for($remision, 'remision')->create([
            'modalidad' => 'EPS', 'valor_base' => null,
        ]);
        $antes = $cita->refresh()->getAttributes();
        Auditoria::creating(static fn () => throw new RuntimeException('Fallo de auditoría simulado'));
        $this->actingAs($this->actor())->patchJson(route('citas.cancelar.update', $cita), $this->confirmar($cita))
            ->assertInternalServerError();

        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertSame($remision->id, $cita->remision_reservada);
        $this->assertSame('08:00:00', $cita->inicio_ocupado);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_seeder_aplica_matriz_propuesta_y_conserva_los_permisos_previos(): void
    {
        $previo = Permiso::query()->create(['codigo' => 'RF-026', 'descripcion' => 'Reprogramar consulta']);
        Rol::query()->create(['nombre' => 'Administrador'])->permisos()->attach($previo);
        $this->seed(CitaCancelacionPermissionsSeeder::class);
        $this->seed(CitaCancelacionPermissionsSeeder::class);

        $permiso = Permiso::query()->where('codigo', 'RF-027')->sole();
        $this->assertEqualsCanonicalizing(['Administrador', 'Médico', 'Administrativo'], $permiso->roles()->pluck('nombre')->all());
        $this->assertSame(['Administrador'], $previo->roles()->pluck('nombre')->all());
        $this->assertDatabaseCount('permiso_rol', 4);
    }

    public function test_migracion_conserva_citas_preexistentes_y_sus_reservas(): void
    {
        $migracion = require database_path('migrations/2026_11_23_005813_add_motivo_cancelacion_to_citas_table.php');
        $migracion->down();

        $remision = RemisionEps::factory()->create();
        $activa = Cita::factory()->for($remision->paciente)->for($remision, 'remision')->create([
            'modalidad' => 'EPS', 'valor_base' => null,
        ]);
        $historica = Cita::factory()->create(['estado' => 'CANCELADA']);
        $activaAntes = $activa->refresh()->getAttributes();
        $historicaAntes = $historica->refresh()->getAttributes();

        $migracion->up();

        $this->assertSame([...$activaAntes, 'motivo_cancelacion' => null], $activa->refresh()->getAttributes());
        $this->assertSame([...$historicaAntes, 'motivo_cancelacion' => null], $historica->refresh()->getAttributes());
        $this->assertSame($remision->id, $activa->remision_reservada);
        $this->assertSame('08:00:00', $activa->inicio_ocupado);
        $this->assertNull($historica->inicio_ocupado);
    }
}
