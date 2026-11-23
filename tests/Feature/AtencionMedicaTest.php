<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\AtencionMedicaPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AtencionMedicaTest extends TestCase
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
        $this->travelTo(Carbon::parse('2026-11-22 07:55:00', 'America/Bogota'));
    }

    /** @param list<string> $permisos */
    private function usuario(array $permisos = ['RF-028']): User
    {
        $rol = Rol::query()->create(['nombre' => 'Rol clínico ficticio '.uniqid()]);
        foreach ($permisos as $codigo) {
            $rol->permisos()->attach(Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $codigo]));
        }

        return User::factory()->for($rol)->create();
    }

    /** @param list<string> $permisos */
    private function medico(array $permisos = ['RF-028']): Medico
    {
        return $this->usuario($permisos)->medico()->create([
            'registro_profesional' => fake()->unique()->numerify('DEMO-########'), 'especialidad' => 'Medicina general',
        ]);
    }

    /** @param array<string, mixed> $atributos */
    private function cita(Medico $medico, array $atributos = [], ?Paciente $paciente = null, bool $conHistoria = true): Cita
    {
        $paciente ??= Paciente::factory()->create();
        if ($conHistoria) {
            $paciente->historiaClinica()->firstOrCreate([], ['fecha_apertura' => now()]);
        }
        $bloque = DisponibilidadMedica::factory()->for($medico)->create([
            'fecha' => $atributos['fecha'] ?? now('America/Bogota')->toDateString(),
            ...Arr::only($atributos, ['hora_inicio', 'hora_fin']),
        ]);

        return Cita::factory()->for($paciente)->for($bloque, 'disponibilidad')->create($atributos);
    }

    public function test_ca02801_abre_una_atencion_y_conserva_la_identidad_clinica_y_la_reserva(): void
    {
        $medico = $this->medico();
        $cita = $this->cita($medico);
        $otraCita = $this->cita($this->medico());
        $historia = $cita->paciente->historiaClinica;
        $antes = $cita->refresh()->getAttributes();
        $historiaAntes = $historia->getAttributes();
        $url = route('atenciones.show', $cita);
        $this->actingAs($medico->user)->get(route('atenciones.index'))->assertOk()->assertSee($url)
            ->assertDontSee(route('atenciones.show', $otraCita));
        $this->get($url)->assertOk()->assertSee('Iniciar atención')
            ->assertDontSee($cita->motivo_consulta);
        $this->assertDatabaseCount('atenciones_medicas', 0);
        $this->assertDatabaseCount('auditorias', 0);

        $this->post(route('atenciones.store', $cita))->assertRedirect($url)->assertSessionHas('status', 'Atención médica iniciada correctamente.');

        $atencion = AtencionMedica::query()->sole();
        $this->assertSame('EN_CURSO', $atencion->estado);
        $this->assertSame($cita->id, $atencion->cita_id);
        $this->assertSame($medico->id, $atencion->medico_id);
        $this->assertSame($historia->id, $atencion->historia_clinica_id);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $atencion->getRawOriginal('fecha_hora_inicio'));
        $this->assertSame([...$antes, 'estado' => 'EN_ATENCION'], $cita->refresh()->getAttributes());
        $this->assertSame($historiaAntes, $historia->refresh()->getAttributes());
        $this->assertNotContains('08:00', array_column($medico->intervalosLibres($cita->fecha->toDateString()), 'inicio'));
        $this->assertDatabaseCount('antecedentes_clinicos', 0);

        $auditoria = Auditoria::query()->sole();
        $this->assertSame('ATENCION_INICIADA', $auditoria->accion);
        $this->assertSame('AtencionMedica', $auditoria->entidad);
        $this->assertSame('EXITO', $auditoria->resultado);
        $this->assertSame($atencion->id, $auditoria->registro_id);
        $this->assertSame($medico->user_id, $auditoria->user_id);
        $this->assertSame($atencion->getRawOriginal('fecha_hora_inicio'), $auditoria->getRawOriginal('fecha_hora'));
        $this->assertStringNotContainsString($cita->motivo_consulta, $auditoria->descripcion);
        $this->get($url)->assertOk()->assertSee('Atención #'.$atencion->id)->assertSee('EN_CURSO')
            ->assertSee('Historia clínica vinculada')->assertDontSee('action="'.$url.'"', false);
    }

    public function test_ca02802_repite_la_solicitud_y_abre_la_misma_atencion_sin_otra_auditoria(): void
    {
        $medico = $this->medico();
        $cita = $this->cita($medico);
        $url = route('atenciones.store', $cita);
        $this->actingAs($medico->user)->post($url)->assertSessionHasNoErrors();
        $antes = AtencionMedica::query()->sole()->getAttributes();
        $this->travel(1)->days();

        $this->post($url)->assertRedirect(route('atenciones.show', $cita))
            ->assertSessionHas('status', 'La atención de esta cita ya está en curso.');
        $this->assertSame($antes, AtencionMedica::query()->sole()->getAttributes());
        $this->assertDatabaseCount('auditorias', 1);
    }

    /** @return array<string, array{string, string, string, ?string}> */
    public static function ventanaDeInicio(): array
    {
        return [
            '10 minutos y 1 segundo antes' => ['2026-11-22', '22:30:00', '2026-11-23 03:19:59', 'desde las 22:20:00'],
            '10 minutos antes' => ['2026-11-22', '22:30:00', '2026-11-23 03:20:00', null],
            '9 minutos antes' => ['2026-11-22', '22:30:00', '2026-11-23 03:21:00', null],
            'hora programada' => ['2026-11-22', '22:30:00', '2026-11-23 03:30:00', null],
            'al final del mismo día' => ['2026-11-22', '22:30:00', '2026-11-23 04:59:59', null],
            'víspera dentro de los 10 minutos' => ['2026-11-23', '00:05:00', '2026-11-23 04:59:59', 'fecha de la cita'],
            'medianoche de la fecha de la cita' => ['2026-11-23', '00:05:00', '2026-11-23 05:00:00', null],
            'medianoche del día siguiente' => ['2026-11-22', '22:30:00', '2026-11-23 05:00:00', 'fecha de la cita'],
        ];
    }

    #[DataProvider('ventanaDeInicio')]
    public function test_valida_fecha_local_y_ventana_al_confirmar(string $fecha, string $hora, string $ahoraUtc, ?string $error): void
    {
        $this->travelTo(Carbon::parse($ahoraUtc, 'UTC'));
        $medico = $this->medico();
        $cita = $this->cita($medico, [
            'fecha' => $fecha, 'hora_inicio' => $hora,
            'hora_fin' => Carbon::parse($fecha.' '.$hora, 'America/Bogota')->addMinutes(30)->format('H:i:s'),
        ]);
        $antes = $cita->refresh()->getAttributes();
        $accion = route('atenciones.store', $cita);
        $pagina = $this->actingAs($medico->user)->get(route('atenciones.show', $cita))->assertOk();

        if ($error !== null) {
            $pagina->assertSee($error)->assertDontSee('action="'.$accion.'"', false);
            $respuesta = $this->postJson($accion)->assertUnprocessable()->assertJsonValidationErrors('cita');
            $this->assertStringContainsString($error, $respuesta->json('errors.cita.0'));
            $this->assertSame($antes, $cita->refresh()->getAttributes());
            $this->assertDatabaseCount('atenciones_medicas', 0);
            $this->assertDatabaseCount('auditorias', 0);

            return;
        }

        $pagina->assertSee('action="'.$accion.'"', false);
        $this->post($accion)->assertRedirect(route('atenciones.show', $cita))->assertSessionHasNoErrors();
        $this->assertSame('EN_ATENCION', $cita->refresh()->estado);
        $this->assertSame('EN_CURSO', $cita->atencion()->sole()->estado);
        $this->assertSame('ATENCION_INICIADA', Auditoria::query()->sole()->accion);
    }

    public function test_revalida_ventana_si_la_cita_se_reprograma_despues_de_abrir_el_formulario(): void
    {
        $medico = $this->medico(['RF-026', 'RF-028']);
        $cita = $this->cita($medico, ['fecha' => '2026-11-22', 'hora_inicio' => '22:30:00', 'hora_fin' => '23:00:00']);
        DisponibilidadMedica::factory()->for($medico)->create([
            'fecha' => '2026-11-22', 'hora_inicio' => '23:00:00', 'hora_fin' => '23:30:00',
        ]);
        $this->travelTo(Carbon::parse('2026-11-22 22:20:00', 'America/Bogota'));
        $accion = route('atenciones.store', $cita);
        $this->actingAs($medico->user)->get(route('atenciones.show', $cita))
            ->assertOk()->assertSee('action="'.$accion.'"', false);
        $this->patch(route('citas.reprogramar.update', $cita), [
            'version_horario' => $cita->refresh()->versionHorario(), 'confirmacion' => '1',
            'fecha' => '2026-11-22', 'hora_inicio' => '23:00',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $antes = $cita->refresh()->getAttributes();

        $respuesta = $this->postJson($accion)->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->assertStringContainsString('desde las 22:50:00', $respuesta->json('errors.cita.0'));
        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertDatabaseCount('atenciones_medicas', 0);
        $this->assertSame('CITA_REPROGRAMADA', Auditoria::query()->sole()->accion);
    }

    public function test_reintento_conserva_una_atencion_previa_iniciada_fuera_de_la_nueva_ventana(): void
    {
        $medico = $this->medico();
        $cita = $this->cita($medico, [
            'fecha' => '2026-11-22', 'hora_inicio' => '22:30:00', 'hora_fin' => '23:00:00', 'estado' => 'EN_ATENCION',
        ]);
        $inicioOriginal = Carbon::parse('2026-11-22 22:07:10', 'America/Bogota')->utc();
        $atencion = AtencionMedica::factory()->for($cita)->create(['fecha_hora_inicio' => $inicioOriginal]);
        $auditoria = Auditoria::query()->create([
            'user_id' => $medico->user_id, 'accion' => 'ATENCION_INICIADA', 'entidad' => 'AtencionMedica',
            'registro_id' => $atencion->id, 'fecha_hora' => $inicioOriginal, 'resultado' => 'EXITO', 'descripcion' => 'Inicio previo.',
        ]);
        $atencionAntes = $atencion->refresh()->getAttributes();
        $auditoriaAntes = $auditoria->refresh()->getAttributes();
        $this->travelTo(Carbon::parse('2026-11-23 03:08:00', 'UTC'));
        $pagina = route('atenciones.show', $cita);

        $this->actingAs($medico->user)->post(route('atenciones.store', $cita))
            ->assertRedirect($pagina)->assertSessionHas('status', 'La atención de esta cita ya está en curso.');
        $this->get($pagina)->assertOk()->assertSee('Atención #'.$atencion->id)->assertSee('22:07:10');
        $this->assertSame($atencionAntes, AtencionMedica::query()->sole()->getAttributes());
        $this->assertSame($auditoriaAntes, Auditoria::query()->sole()->getAttributes());
    }

    public function test_base_de_datos_restringe_a_una_atencion_por_cita(): void
    {
        $existente = AtencionMedica::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        AtencionMedica::factory()->for($existente->cita)->create();
    }

    public function test_ca02803_no_expone_ni_inicia_citas_de_otro_medico(): void
    {
        $cita = $this->cita($this->medico());
        $antes = $cita->refresh()->getAttributes();
        $this->actingAs($this->medico()->user)->get(route('atenciones.show', $cita))
            ->assertNotFound()->assertDontSee($cita->paciente->nombres);
        $this->postJson(route('atenciones.store', $cita))->assertNotFound();
        $this->postJson(route('atenciones.store', 99999999))->assertNotFound();
        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertDatabaseCount('atenciones_medicas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_ca02804_exige_sesion_cuenta_activa_perfil_y_permiso_independiente(): void
    {
        $titular = $this->medico(['RF-024', 'RF-025', 'RF-026', 'RF-027', 'RF-058']);
        $cita = $this->cita($titular);
        $url = route('atenciones.store', $cita);
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs($titular->user)->postJson($url)->assertForbidden();
        $this->actingAs($this->usuario())->get(route('atenciones.index'))->assertForbidden();
        $this->postJson($url)->assertForbidden();

        $medico = $this->medico();
        $citaPropia = $this->cita($medico);
        $medico->update(['especialidad' => '']);
        $this->actingAs($medico->user)->postJson(route('atenciones.store', $citaPropia))->assertForbidden();
        $medico->update(['especialidad' => 'Medicina general']);
        $medico->user->forceFill(['estado' => 'inactivo'])->save();
        $this->actingAs($medico->user)->postJson(route('atenciones.store', $citaPropia))->assertRedirect(route('login'));

        $this->assertSame('PROGRAMADA', $cita->refresh()->estado);
        $this->assertSame('PROGRAMADA', $citaPropia->refresh()->estado);
        $this->assertDatabaseCount('atenciones_medicas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_ca02804_rechaza_identidad_y_datos_clinicos_enviados_desde_el_cliente(): void
    {
        $medico = $this->medico();
        $cita = $this->cita($medico);
        $this->actingAs($medico->user)->postJson(route('atenciones.store', $cita), [
            'cita_id' => 123, 'medico_id' => $medico->id, 'historia_clinica_id' => 456, 'motivo_consulta' => 'Motivo ficticio',
        ])->assertUnprocessable()->assertJsonValidationErrors(['cita_id', 'medico_id', 'historia_clinica_id', 'motivo_consulta']);
        $this->assertSame('PROGRAMADA', $cita->refresh()->estado);
        $this->assertDatabaseCount('atenciones_medicas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function precondicionesInvalidas(): array
    {
        return [
            'cancelada' => ['CANCELADA', 'PROGRAMADA'], 'atendida' => ['ATENDIDA', 'PROGRAMADA'],
            'estado en atención sin registro' => ['EN_ATENCION', 'PROGRAMADA'],
            'pasada' => ['2026-11-21', 'fecha de la cita'], 'futura' => ['2026-11-23', 'fecha de la cita'],
            'paciente inactivo' => ['paciente', 'paciente debe estar ACTIVO'],
            'sin historia' => ['historia', 'historia clínica vinculada'],
        ];
    }

    #[DataProvider('precondicionesInvalidas')]
    public function test_rechaza_citas_no_elegibles_sin_cambios_parciales(string $caso, string $mensaje): void
    {
        $medico = $this->medico();
        $atributos = match ($caso) {
            'CANCELADA', 'ATENDIDA', 'EN_ATENCION' => ['estado' => $caso],
            '2026-11-21', '2026-11-23' => ['fecha' => $caso],
            default => [],
        };
        $cita = $this->cita($medico, $atributos, conHistoria: $caso !== 'historia');
        if ($caso === 'paciente') {
            $cita->paciente->forceFill(['estado' => 'INACTIVO'])->save();
        }
        $antes = $cita->refresh()->getAttributes();
        $response = $this->actingAs($medico->user)->postJson(route('atenciones.store', $cita))
            ->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->assertStringContainsString($mensaje, $response->json('errors.cita.0'));
        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertDatabaseCount('atenciones_medicas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_fecha_local_de_colombia_y_vigencia_eps_inclusiva_sin_consumir_remision(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 02:00:00', 'UTC'));
        $medico = $this->medico();
        $remision = RemisionEps::factory()->create(['fecha_remision' => '2026-11-22', 'vigencia_hasta' => '2026-11-22']);
        $cita = $this->cita($medico, ['modalidad' => 'EPS', 'remision_id' => $remision->id, 'valor_base' => null], $remision->paciente);
        $antes = $remision->refresh()->getAttributes();

        $this->actingAs($medico->user)->get(route('atenciones.index'))->assertSee(route('atenciones.show', $cita));
        $this->post(route('atenciones.store', $cita))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('EN_ATENCION', $cita->refresh()->estado);
        $this->assertSame('EN_CURSO', $cita->atencion->estado);
        $this->assertSame($remision->id, $cita->remision_reservada);
        $this->assertSame($antes, $remision->refresh()->getAttributes());
        $this->assertDatabaseCount('cambios_estado_remision', 0);
    }

    public static function remisionesInvalidas(): array
    {
        return [['USADA'], ['ANULADA'], ['VENCIDA'], ['otro paciente'], ['EPS inactiva'], ['emisión posterior']];
    }

    #[DataProvider('remisionesInvalidas')]
    public function test_rechaza_eps_invalidas_sin_consumir_remision(string $caso): void
    {
        $remision = RemisionEps::factory()->create();
        match ($caso) {
            'USADA', 'ANULADA' => $remision->forceFill(['estado' => $caso])->save(),
            'VENCIDA' => $remision->update(['fecha_remision' => '2026-11-20', 'vigencia_hasta' => '2026-11-21']),
            'emisión posterior' => $remision->update(['fecha_remision' => '2026-11-23']),
            'EPS inactiva' => $remision->eps->forceFill(['estado' => 'INACTIVA'])->save(),
            default => null,
        };
        $medico = $this->medico();
        $paciente = $caso === 'otro paciente' ? Paciente::factory()->create() : $remision->paciente;
        $cita = $this->cita($medico, ['modalidad' => 'EPS', 'remision_id' => $remision->id, 'valor_base' => null], $paciente);
        $antes = $remision->refresh()->getAttributes();

        $this->actingAs($medico->user)->postJson(route('atenciones.store', $cita))
            ->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->assertSame($antes, $remision->refresh()->getAttributes());
        $this->assertSame('PROGRAMADA', $cita->refresh()->estado);
        $this->assertDatabaseCount('atenciones_medicas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_inicio_impide_reprogramar_cancelar_o_inactivar_una_atencion_en_curso(): void
    {
        $medico = $this->medico(['RF-012', 'RF-026', 'RF-027', 'RF-028']);
        $cita = $this->cita($medico);
        $this->actingAs($medico->user)->post(route('atenciones.store', $cita))->assertSessionHasNoErrors();
        $cita->refresh();

        $this->patchJson(route('citas.cancelar.update', $cita), [
            'version_horario' => $cita->versionHorario(), 'confirmacion' => '1', 'motivo_cancelacion' => 'Prueba ficticia',
        ])->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->patchJson(route('citas.reprogramar.update', $cita), [
            'version_horario' => $cita->versionHorario(), 'confirmacion' => '1', 'fecha' => $cita->fecha->toDateString(), 'hora_inicio' => '08:30',
        ])->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->patchJson(route('pacientes.inactivate', $cita->paciente), [
            'motivo' => 'Prueba ficticia', 'confirmacion' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors('motivo');

        $this->assertSame('ACTIVO', $cita->paciente->refresh()->estado);
        $this->assertSame('EN_ATENCION', $cita->refresh()->estado);
        $this->assertSame('EN_CURSO', AtencionMedica::query()->sole()->estado);
        $this->assertSame('ATENCION_INICIADA', Auditoria::query()->sole()->accion);
    }

    public function test_inicio_rechaza_cita_que_se_cancelo_tras_revisarla(): void
    {
        $medico = $this->medico(['RF-027', 'RF-028']);
        $cita = $this->cita($medico);
        $this->actingAs($medico->user)->get(route('atenciones.show', $cita))->assertOk()->assertSee('Iniciar atención');
        $this->patch(route('citas.cancelar.update', $cita), [
            'version_horario' => $cita->refresh()->versionHorario(), 'confirmacion' => '1', 'motivo_cancelacion' => 'Prueba ficticia',
        ])->assertSessionHasNoErrors();
        $this->postJson(route('atenciones.store', $cita))->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->assertSame('CANCELADA', $cita->refresh()->estado);
        $this->assertDatabaseCount('atenciones_medicas', 0);
        $this->assertSame('CITA_CANCELADA', Auditoria::query()->sole()->accion);
    }

    public function test_inicio_revalida_paciente_que_se_inactivo_tras_revisar_la_cita(): void
    {
        $medico = $this->medico(['RF-012', 'RF-028']);
        $cita = $this->cita($medico);
        $this->actingAs($medico->user)->get(route('atenciones.show', $cita))->assertOk()->assertSee('Iniciar atención');
        $this->patch(route('pacientes.inactivate', $cita->paciente), [
            'motivo' => 'Prueba ficticia', 'confirmacion' => '1',
        ])->assertSessionHasNoErrors();
        $this->postJson(route('atenciones.store', $cita))->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->assertSame('INACTIVO', $cita->paciente->refresh()->estado);
        $this->assertSame('PROGRAMADA', $cita->refresh()->estado);
        $this->assertDatabaseCount('atenciones_medicas', 0);
        $this->assertSame('PACIENTE_INACTIVADO', Auditoria::query()->sole()->accion);
    }

    public function test_fallo_de_auditoria_no_abre_atencion_ni_cambia_cita(): void
    {
        $medico = $this->medico();
        $cita = $this->cita($medico);
        $antes = $cita->refresh()->getAttributes();
        Auditoria::creating(static fn () => throw new RuntimeException('Fallo de auditoría simulado'));

        $this->actingAs($medico->user)->postJson(route('atenciones.store', $cita))->assertInternalServerError();
        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertDatabaseCount('atenciones_medicas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_seeder_aplica_la_matriz_propuesta_sin_quitar_permisos_previos(): void
    {
        $previo = Permiso::query()->create(['codigo' => 'RF-027', 'descripcion' => 'Cancelar consulta']);
        Rol::query()->create(['nombre' => 'Administrativo'])->permisos()->attach($previo);
        $this->seed(AtencionMedicaPermissionsSeeder::class);
        $this->seed(AtencionMedicaPermissionsSeeder::class);

        $permiso = Permiso::query()->where('codigo', 'RF-028')->sole();
        $this->assertEqualsCanonicalizing(['Administrador', 'Médico'], $permiso->roles()->pluck('nombre')->all());
        $this->assertSame(['Administrativo'], $previo->roles()->pluck('nombre')->all());
        $this->assertDatabaseCount('permiso_rol', 3);
    }
}
