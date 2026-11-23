<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\CitaReprogramacionPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CitaReprogramacionTest extends TestCase
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

    private function actor(): User
    {
        $permiso = Permiso::query()->firstOrCreate(['codigo' => 'RF-026'], ['descripcion' => 'Reprogramar consulta']);
        $rol = Rol::query()->create(['nombre' => 'Agenda ficticia '.uniqid()]);
        $rol->permisos()->attach($permiso);

        return User::factory()->for($rol)->create();
    }

    private function confirmar(Cita $cita, array $cambios = []): array
    {
        return [
            'fecha' => $cita->fecha->toDateString(), 'hora_inicio' => '08:30', 'confirmacion' => '1',
            'version_horario' => $cita->versionHorario(), ...$cambios,
        ];
    }

    public function test_ca02601_actualiza_misma_cita_y_audita_intervalos(): void
    {
        $cita = Cita::factory()->create();
        $bloqueNuevo = DisponibilidadMedica::factory()->create([
            'medico_id' => $cita->medico_id, 'fecha' => '2026-11-23', 'duracion_minutos' => 20,
        ]);
        $actor = $this->actor();
        $antes = $cita->horario();

        $this->actingAs($actor)->get(route('citas.reprogramar.index'))->assertOk()->assertSee('ID de la cita');
        $this->get(route('citas.reprogramar.index', ['cita_id' => $cita->id]))
            ->assertRedirect(route('citas.reprogramar.edit', $cita));
        $formulario = route('citas.reprogramar.edit', ['citaId' => $cita, 'fecha' => '2026-11-23']);
        $this->get($formulario)->assertOk()->assertSee($cita->versionHorario())->assertSee('08:20–08:40');
        $this->from($formulario)->patch(route('citas.reprogramar.update', $cita), $this->confirmar($cita, [
            'fecha' => '2026-11-23', 'hora_inicio' => '08:20',
        ]))->assertRedirect(route('citas.reprogramar.edit', $cita))->assertSessionHasNoErrors();
        $this->get(route('citas.reprogramar.edit', $cita))
            ->assertSee('Consulta reprogramada correctamente.')->assertSee('2026-11-23 08:20:00–08:40:00');

        $guardada = Cita::query()->sole();
        $this->assertSame($cita->id, $guardada->id);
        $this->assertSame('PROGRAMADA', $guardada->estado);
        $this->assertSame($bloqueNuevo->id, $guardada->disponibilidad_medica_id);
        $this->assertSame('2026-11-23 08:20:00–08:40:00', $guardada->horario());
        foreach (['paciente_id', 'medico_id', 'modalidad', 'tipo_paciente_al_agendar', 'motivo_consulta', 'valor_base', 'created_by'] as $atributo) {
            $this->assertSame($cita->{$atributo}, $guardada->{$atributo});
        }
        $this->assertContains('08:00', array_column($cita->medico->intervalosLibres('2026-11-22'), 'inicio'));
        $this->assertNotContains('08:20', array_column($cita->medico->intervalosLibres('2026-11-23'), 'inicio'));
        $auditoria = Auditoria::query()->sole();
        $this->assertSame($actor->id, $auditoria->user_id);
        $this->assertSame($cita->id, $auditoria->registro_id);
        $this->assertSame('CITA_REPROGRAMADA', $auditoria->accion);
        $this->assertStringContainsString('Antes: '.$antes, $auditoria->descripcion);
        $this->assertStringContainsString('Después: '.$guardada->horario(), $auditoria->descripcion);
    }

    public function test_ca02602_rechaza_solapamiento_parcial_aunque_antes_estuviera_libre(): void
    {
        $cita = Cita::factory()->create();
        $this->actingAs($this->actor())->get(route('citas.reprogramar.edit', $cita))
            ->assertOk()->assertSee('08:30–09:00');
        Cita::factory()->for($cita->disponibilidad, 'disponibilidad')->create([
            'estado' => 'ATENDIDA', 'hora_inicio' => '08:45:00', 'hora_fin' => '09:15:00',
        ]);

        $antes = $cita->refresh()->getAttributes();
        $this->patchJson(route('citas.reprogramar.update', $cita), $this->confirmar($cita))
            ->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');
        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertDatabaseCount('citas', 2);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_ca02603_eps_mantiene_remision_y_respeta_fin_de_vigencia_inclusivo(): void
    {
        $remision = RemisionEps::factory()->create(['vigencia_hasta' => '2026-11-23']);
        $cita = Cita::factory()->for($remision->paciente)->for($remision, 'remision')->create([
            'modalidad' => 'EPS', 'valor_base' => null,
        ]);
        foreach (['2026-11-23', '2026-11-24'] as $fecha) {
            DisponibilidadMedica::factory()->create(['medico_id' => $cita->medico_id, 'fecha' => $fecha]);
        }
        $antes = $cita->refresh()->getAttributes();
        $remisionAntes = $remision->refresh()->getAttributes();
        $url = route('citas.reprogramar.update', $cita);

        $this->actingAs($this->actor())->patchJson($url, $this->confirmar($cita, ['fecha' => '2026-11-24']))
            ->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertSame($remisionAntes, $remision->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
        $this->patch($url, $this->confirmar($cita, ['fecha' => '2026-11-23']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('2026-11-23 08:30:00–09:00:00', $cita->refresh()->horario());
        $this->assertSame('EPS', $cita->modalidad);
        $this->assertSame($remision->id, $cita->remision_id);
        $this->assertTrue($remision->citas()->ocupaHorario()->sole()->is($cita));
        $this->assertSame($remisionAntes, $remision->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 1);
    }

    public function test_ca02604_protege_consulta_y_escritura_con_permiso_rf026(): void
    {
        $cita = Cita::factory()->create();
        $url = route('citas.reprogramar.update', $cita);
        $datos = $this->confirmar($cita);
        $this->patchJson($url, $datos)->assertUnauthorized();

        $rol = Rol::query()->create(['nombre' => 'Solo agendamiento']);
        foreach (['RF-024', 'RF-025'] as $codigo) {
            $rol->permisos()->attach(Permiso::query()->create(['codigo' => $codigo, 'descripcion' => $codigo]));
        }
        $this->actingAs(User::factory()->for($rol)->create());
        $this->get(route('citas.reprogramar.index'))->assertForbidden();
        $this->get(route('citas.reprogramar.edit', $cita))->assertForbidden()->assertDontSee($cita->paciente->nombres);
        $this->get(route('citas.reprogramar.edit', 9999999))->assertForbidden();
        $this->patchJson($url, $datos)->assertForbidden();

        $this->actingAs($this->actor()->forceFill(['estado' => 'inactivo']));
        $this->patchJson($url, $datos)->assertRedirect(route('login'));

        $this->actingAs($this->actor())->patchJson($url, $this->confirmar($cita, [
            'fecha' => '2026-02-30', 'hora_inicio' => '29:00', 'confirmacion' => '0',
            'remision_id' => 1, 'medico_id' => 2, 'modalidad' => 'EPS', 'hora_fin' => '10:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'fecha', 'hora_inicio', 'confirmacion', 'remision_id', 'medico_id', 'modalidad', 'hora_fin',
        ]);
        $this->assertSame('08:00:00', $cita->refresh()->hora_inicio);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function condicionesInvalidas(): array
    {
        return [
            'cancelada' => ['CANCELADA', 'cita'],
            'en atención' => ['EN_ATENCION', 'cita'],
            'atendida' => ['ATENDIDA', 'cita'],
            'paciente inactivo' => ['paciente', 'cita'],
            'médico inactivo' => ['medico', 'cita'],
            'intervalo incompleto' => ['08:10', 'hora_inicio'],
            'fecha pasada' => ['pasado', 'hora_inicio'],
        ];
    }

    #[DataProvider('condicionesInvalidas')]
    public function test_rechaza_condiciones_invalidas_sin_escribir(string $condicion, string $error): void
    {
        $cita = Cita::factory()->create();
        $datos = match ($condicion) {
            '08:10' => ['hora_inicio' => '08:10'],
            'pasado' => ['fecha' => '2026-11-21'],
            default => [],
        };
        match ($condicion) {
            'CANCELADA', 'EN_ATENCION', 'ATENDIDA' => $cita->forceFill(['estado' => $condicion])->save(),
            'paciente' => $cita->paciente->forceFill(['estado' => 'INACTIVO'])->save(),
            'medico' => $cita->medico->user->forceFill(['estado' => 'inactivo'])->save(),
            default => null,
        };

        $this->actingAs($this->actor())->patchJson(route('citas.reprogramar.update', $cita), $this->confirmar($cita, $datos))
            ->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertSame('08:00:00', $cita->refresh()->hora_inicio);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_mismo_horario_informa_y_no_modifica_la_cita(): void
    {
        $cita = Cita::factory()->create();
        $antes = $cita->refresh()->getAttributes();
        $this->actingAs($this->actor())->get(route('citas.reprogramar.edit', $cita))
            ->assertOk()->assertSee('08:00–08:30 (horario actual)');
        $this->patch(route('citas.reprogramar.update', $cita), $this->confirmar($cita, ['hora_inicio' => '08:00']))
            ->assertRedirect()->assertSessionHas('status', 'La cita ya tiene el horario seleccionado.');
        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_formulario_concurrente_no_sobrescribe_cambio_confirmado(): void
    {
        $cita = Cita::factory()->create();
        $datosPrimerActor = $this->confirmar($cita);
        $datosSegundoActor = $this->confirmar($cita, ['hora_inicio' => '09:00']);
        $url = route('citas.reprogramar.update', $cita);

        $this->actingAs($this->actor())->patch($url, $datosPrimerActor)->assertSessionHasNoErrors();
        $this->actingAs($this->actor())->patchJson($url, $datosSegundoActor)
            ->assertUnprocessable()->assertJsonValidationErrors('hora_inicio')
            ->assertJsonPath('errors.hora_inicio.0', 'La cita cambió mientras elegía el horario. Revise la cita y confirme de nuevo.');

        $this->assertSame('08:30:00', $cita->refresh()->hora_inicio);
        $this->assertDatabaseCount('auditorias', 1);
    }

    public function test_un_fallo_de_auditoria_revierte_el_cambio(): void
    {
        $cita = Cita::factory()->create();
        Auditoria::creating(static fn () => throw new RuntimeException('Fallo de auditoría simulado'));
        $this->actingAs($this->actor())->patchJson(route('citas.reprogramar.update', $cita), $this->confirmar($cita))
            ->assertInternalServerError();

        $this->assertSame('08:00:00', $cita->refresh()->hora_inicio);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_seeder_publica_permiso_configurable_sin_duplicados(): void
    {
        $this->seed(CitaReprogramacionPermissionsSeeder::class);
        $this->seed(CitaReprogramacionPermissionsSeeder::class);
        $permiso = Permiso::query()->where('codigo', 'RF-026')->sole();
        $this->assertEqualsCanonicalizing(['Administrador', 'Médico', 'Administrativo'], $permiso->roles()->pluck('nombre')->all());
    }
}
