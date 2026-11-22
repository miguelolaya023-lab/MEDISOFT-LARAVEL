<?php

namespace Tests\Feature;

use App\Http\Requests\StoreCitaEpsRequest;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Eps;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\CitaEpsPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CitaEpsTest extends TestCase
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

    private function actor(array $codigos = ['RF-025'], array $atributos = []): User
    {
        $rol = Rol::query()->create(['nombre' => 'Agenda EPS ficticia '.uniqid()]);
        foreach ($codigos as $codigo) {
            $rol->permisos()->attach(Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $codigo]));
        }

        return User::factory()->for($rol)->create($atributos);
    }

    /** @return array{RemisionEps, DisponibilidadMedica, array<string, mixed>} */
    private function escenario(): array
    {
        $remision = RemisionEps::factory()->for(Paciente::factory()->state(['tipo_paciente' => 'PARTICULAR']))->create([
            'fecha_remision' => '2026-11-22', 'vigencia_hasta' => '2026-11-23',
        ]);
        $bloque = DisponibilidadMedica::factory()->create(['fecha' => '2026-11-23']);

        return [$remision, $bloque, [
            'paciente_id' => $remision->paciente_id, 'remision_id' => $remision->id, 'medico_id' => $bloque->medico_id,
            'fecha' => '2026-11-23', 'hora_inicio' => '08:00', 'motivo_consulta' => 'Motivo ficticio de RF-025', 'confirmacion' => '1',
        ]];
    }

    private function citaEps(RemisionEps $remision, DisponibilidadMedica $bloque, array $atributos = []): Cita
    {
        return Cita::factory()->create([
            'remision_id' => $remision->id, 'paciente_id' => $remision->paciente_id, 'disponibilidad_medica_id' => $bloque->id,
            'modalidad' => 'EPS', 'valor_base' => null, ...$atributos,
        ]);
    }

    public function test_ca02501_agenda_y_audita_sin_consumir_ni_reclasificar(): void
    {
        [$remision, $bloque, $datos] = $this->escenario();
        $actor = $this->actor();
        $antes = $remision->refresh()->getAttributes();

        $this->actingAs($actor)->post(route('citas.eps.store'), $datos)->assertRedirect();
        $cita = Cita::query()->sole();
        $this->assertDatabaseHas('citas', [
            'id' => $cita->id, 'paciente_id' => $remision->paciente_id, 'remision_id' => $remision->id,
            'medico_id' => $bloque->medico_id, 'disponibilidad_medica_id' => $bloque->id, 'created_by' => $actor->id,
            'hora_inicio' => '08:00:00', 'hora_fin' => '08:30:00',
            'modalidad' => 'EPS', 'valor_base' => null, 'estado' => 'PROGRAMADA',
            'tipo_paciente_al_agendar' => 'PARTICULAR', 'motivo_consulta' => $datos['motivo_consulta'],
        ]);
        $this->assertSame($datos['fecha'], $cita->fecha->toDateString());
        $this->assertSame($antes, $remision->refresh()->getAttributes());
        $this->assertSame('PARTICULAR', $remision->paciente->tipo_paciente);
        $this->assertTrue($cita->remision->is($remision));
        $this->assertTrue($remision->citas()->sole()->is($cita));
        $auditoria = Auditoria::query()->sole();
        $this->assertSame('CITA_EPS_CREADA', $auditoria->accion);
        $this->assertSame($cita->id, $auditoria->registro_id);
        $this->assertStringNotContainsString($datos['motivo_consulta'], $auditoria->toJson());
        $this->get(route('citas.eps.confirmation', $cita))->assertOk()->assertSee($remision->numero_remision);
        $this->actingAs($this->actor())->get(route('citas.eps.confirmation', $cita))->assertForbidden();
        $this->actingAs($actor)->get(route('citas.confirmation', $cita))->assertForbidden();
    }

    public function test_acepta_fecha_de_emision_inclusiva_en_el_mismo_dia(): void
    {
        [$remision, $bloque, $datos] = $this->escenario();
        $bloque->update(['fecha' => $remision->fecha_remision->toDateString()]);
        $this->actingAs($this->actor())->post(route('citas.eps.store'), [...$datos, 'fecha' => '2026-11-22'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('2026-11-22', $remision->citas()->sole()->fecha->toDateString());
    }

    public static function remisionesInvalidas(): array
    {
        return [
            'CA-025-02 otro paciente' => ['otro_paciente'],
            'fecha posterior a la vigencia' => ['fuera_de_vigencia'],
            'fecha anterior a la emisión' => ['antes_de_emision'],
            'vencida' => ['VENCIDA'],
            'usada' => ['USADA'],
            'anulada' => ['ANULADA'],
            'EPS inactiva' => ['eps_inactiva'],
        ];
    }

    #[DataProvider('remisionesInvalidas')]
    public function test_rechaza_remision_no_elegible(string $caso): void
    {
        [$remision, , $datos] = $this->escenario();
        match ($caso) {
            'otro_paciente' => $datos['paciente_id'] = Paciente::factory()->create()->id,
            'fuera_de_vigencia' => $remision->update(['vigencia_hasta' => '2026-11-22']),
            'antes_de_emision' => $remision->update(['fecha_remision' => '2026-11-24', 'vigencia_hasta' => '2026-11-25']),
            'eps_inactiva' => $remision->eps->forceFill(['estado' => 'INACTIVA'])->save(),
            default => $remision->forceFill(['estado' => $caso])->save(),
        };
        $antes = $remision->refresh()->getAttributes();

        $this->actingAs($this->actor())->postJson(route('citas.eps.store'), $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('remision_id')->assertDontSee($remision->numero_remision);

        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
        $this->assertSame($antes, $remision->refresh()->getAttributes());
    }

    public function test_ca02503_conserva_canceladas_y_rechaza_segunda_reserva_aun_con_otro_medico(): void
    {
        [$remision, $bloque, $datos] = $this->escenario();
        $previa = $this->citaEps($remision, $bloque, ['estado' => 'CANCELADA']);
        $antes = $previa->refresh()->getAttributes();
        $otroBloque = DisponibilidadMedica::factory()->create(['fecha' => $datos['fecha']]);

        $this->actingAs($this->actor())->post(route('citas.eps.store'), $datos)->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson(route('citas.eps.store'), [...$datos, 'medico_id' => $otroBloque->medico_id])
            ->assertUnprocessable()->assertJsonValidationErrors('remision_id');

        $this->assertSame($antes, $previa->refresh()->getAttributes());
        $this->assertDatabaseCount('citas', 2);
        $this->assertSame(1, $remision->citas()->ocupaHorario()->count());
        $this->assertDatabaseCount('auditorias', 1);
    }

    public function test_la_previsualizacion_muestra_solo_remisiones_elegibles_del_paciente_y_rejilla_libre(): void
    {
        [$remision, $bloque, $datos] = $this->escenario();
        $ajena = RemisionEps::factory()->create();
        $usada = RemisionEps::factory()->for($remision->paciente)->create(['estado' => 'USADA']);
        $inactiva = RemisionEps::factory()->for($remision->paciente)->for(Eps::factory()->state(['estado' => 'INACTIVA']), 'eps')->create();
        $reservada = RemisionEps::factory()->for($remision->paciente)->create();
        $this->citaEps($reservada, $bloque);

        $this->actingAs($this->actor())->get(route('citas.eps.create', [
            'paciente_id' => $datos['paciente_id'], 'medico_id' => $datos['medico_id'], 'fecha' => $datos['fecha'],
        ]))->assertOk()->assertSee($remision->numero_remision)->assertSee('08:30–09:00')
            ->assertDontSee('08:00–08:30')->assertDontSee($ajena->numero_remision)->assertDontSee($usada->numero_remision)
            ->assertDontSee($inactiva->numero_remision)->assertDontSee($reservada->numero_remision);
        $this->assertDatabaseCount('citas', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_solape_con_particular_y_con_eps_en_ambos_flujos(): void
    {
        [$remision, $bloque, $datos] = $this->escenario();
        Cita::factory()->create([
            'disponibilidad_medica_id' => $bloque->id, 'hora_inicio' => '08:15:00', 'hora_fin' => '08:30:00',
        ]);

        $this->actingAs($this->actor(['RF-024', 'RF-025']))->postJson(route('citas.eps.store'), $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');
        $this->post(route('citas.eps.store'), [...$datos, 'hora_inicio' => '08:30'])->assertRedirect()->assertSessionHasNoErrors();

        unset($datos['remision_id']);
        $this->postJson(route('citas.store'), [...$datos, 'hora_inicio' => '08:30', 'valor_base' => '10000'])
            ->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');
        $this->assertDatabaseCount('citas', 2);
        $this->assertSame(1, $remision->citas()->count());
        $this->assertDatabaseCount('auditorias', 1);
    }

    public function test_ca02504_exige_permiso_independiente_y_rechaza_campos_manipulados(): void
    {
        [, , $datos] = $this->escenario();

        $this->get(route('citas.eps.create'))->assertRedirect(route('login'));
        $this->actingAs($this->actor(['RF-024']))->get(route('citas.eps.create'))->assertForbidden();
        $this->postJson(route('citas.eps.store'), $datos)->assertForbidden();
        $this->actingAs($this->actor(atributos: ['estado' => 'inactivo']))->postJson(route('citas.eps.store'), $datos)->assertRedirect(route('login'));

        $this->actingAs($this->actor());
        foreach (['eps_id', 'modalidad', 'estado', 'valor_base', 'hora_fin', 'created_by'] as $campo) {
            $this->postJson(route('citas.eps.store'), [...$datos, $campo => 1])->assertUnprocessable()->assertJsonValidationErrors($campo);
        }
        $this->postJson(route('citas.eps.store'), [...$datos, 'hora_inicio' => '7:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_rechaza_paciente_inactivo_medico_no_habilitado_y_horario_pasado(): void
    {
        [$remision, $bloque, $datos] = $this->escenario();
        $this->actingAs($this->actor());
        $remision->paciente->forceFill(['estado' => 'INACTIVO'])->save();
        $this->postJson(route('citas.eps.store'), $datos)->assertUnprocessable()->assertJsonValidationErrors('paciente_id');
        $remision->paciente->forceFill(['estado' => 'ACTIVO'])->save();
        $bloque->medico->user->forceFill(['estado' => 'inactivo'])->save();
        $this->postJson(route('citas.eps.store'), $datos)->assertUnprocessable()->assertJsonValidationErrors('medico_id');
        $bloque->medico->user->forceFill(['estado' => 'activo'])->save();
        $this->travelTo(Carbon::parse('2026-11-23 08:00:00', 'America/Bogota'));
        $this->postJson(route('citas.eps.store'), $datos)->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_revalida_remision_tras_validar_la_solicitud(): void
    {
        [$remision, , $datos] = $this->escenario();
        $this->app->afterResolving(StoreCitaEpsRequest::class, function () use ($remision): void {
            $remision->forceFill(['estado' => 'ANULADA'])->save();
        });

        $this->actingAs($this->actor())->postJson(route('citas.eps.store'), $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('remision_id');

        $this->assertSame('ANULADA', $remision->refresh()->estado);
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_disponibilidad_preserva_intervalo_de_cita_eps(): void
    {
        [$remision, $bloque, $datos] = $this->escenario();
        $cita = $this->citaEps($remision, $bloque);
        $medico = $bloque->medico->user;
        $medico->forceFill(['rol_id' => $this->actor(['RF-060'])->rol_id])->save();

        $this->actingAs($medico)->patchJson(route('disponibilidad.update', $bloque), [
            'fecha' => $datos['fecha'], 'hora_inicio' => '08:00', 'hora_fin' => '10:00',
            'duracion_minutos' => 60, 'confirmacion' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');

        $this->assertSame(30, $bloque->refresh()->duracion_minutos);
        $this->assertSame('08:30:00', $cita->refresh()->hora_fin);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_fallo_sql_en_auditoria_revierte_cita_y_reserva_sin_revelar_sql(): void
    {
        [$remision, , $datos] = $this->escenario();
        $actor = $this->actor();
        $antes = $remision->refresh()->getAttributes();
        Log::spy();
        DB::unprepared("CREATE TEMP TRIGGER rf025_fallo_auditoria BEFORE INSERT ON auditorias BEGIN SELECT RAISE(ABORT, 'diagnostico ficticio'); END");

        try {
            $this->actingAs($actor)->postJson(route('citas.eps.store'), $datos)
                ->assertServiceUnavailable()->assertDontSee('diagnostico ficticio')->assertDontSee($datos['motivo_consulta']);
        } finally {
            DB::unprepared('DROP TRIGGER rf025_fallo_auditoria');
        }

        Log::shouldHaveReceived('error')->once()->with('Error de base de datos en citas EPS.', [
            'modulo' => 'RF-025', 'operacion' => 'citas.eps.store',
        ]);
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
        $this->assertSame($antes, $remision->refresh()->getAttributes());
        $this->post(route('citas.eps.store'), $datos)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('citas', 1);
    }

    public function test_correccion_y_anulacion_no_invalidan_cita_asociada(): void
    {
        [$remision, $bloque] = $this->escenario();
        $cita = $this->citaEps($remision, $bloque);
        $this->actingAs($this->actor(['RF-020', 'RF-023']));
        $editar = ['servicio_autorizado' => $remision->servicio_autorizado, 'vigencia_hasta' => '2026-11-22'];
        $anular = ['estado' => 'ANULADA', 'motivo' => 'Prueba ficticia', 'confirmacion' => '1'];

        $this->patchJson(route('remisiones.update', $remision), $editar)->assertUnprocessable()->assertJsonValidationErrors('vigencia_hasta');
        $this->patchJson(route('remisiones.changeState', $remision), $anular)->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->assertDatabaseCount('auditorias', 0);
        $this->assertSame('VIGENTE', $remision->refresh()->estado);

        $cita->forceFill(['estado' => 'CANCELADA'])->save();
        $this->patchJson(route('remisiones.update', $remision), $editar)->assertRedirect();
        $this->patchJson(route('remisiones.changeState', $remision), $anular)->assertRedirect();
        $this->assertSame('ANULADA', $remision->refresh()->estado);
        $this->assertSame($remision->id, $cita->refresh()->remision_id);
        $this->assertSame('CANCELADA', $cita->estado);
    }

    public function test_indice_impide_dos_reservas_de_la_remision_y_preserva_indices_y_fk(): void
    {
        [$remision, $bloque, $datos] = $this->escenario();
        $segundoBloque = DisponibilidadMedica::factory()->create(['fecha' => $datos['fecha']]);
        $this->citaEps($remision, $bloque, ['estado' => 'CANCELADA']);
        $this->citaEps($remision, $bloque, ['estado' => 'CANCELADA']);
        $this->citaEps($remision, $bloque);
        $this->assertTrue(Schema::hasIndex('citas', 'citas_medico_fecha_inicio_ocupado_unique', 'unique'));
        $this->assertTrue(Schema::hasIndex('citas', 'citas_remision_reservada_unique', 'unique'));
        $this->assertContains(['remision_id'], array_column(Schema::getForeignKeys('citas'), 'columns'));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('citas.remision_reservada');
        $this->citaEps($remision, $segundoBloque);
    }

    public function test_fk_rechaza_una_cita_eps_con_remision_inexistente(): void
    {
        [$remision, $bloque] = $this->escenario();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');
        $this->citaEps($remision, $bloque, ['remision_id' => $remision->id + 1000]);
    }

    public function test_migracion_incremental_preserva_cita_particular_y_restringe_modalidad(): void
    {
        $migracion = require database_path('migrations/2026_11_22_194447_add_eps_booking_to_citas_table.php');
        $migracion->down();
        $particular = Cita::factory()->create()->fresh();
        $antes = $particular->getAttributes();
        $migracion->up();
        $particular->refresh();

        $this->assertSame($antes, array_intersect_key($particular->getAttributes(), $antes));
        $this->assertNull($particular->remision_id);
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        try {
            $particular->update(['valor_base' => null]);
            $this->fail('Una cita particular debe tener un importe positivo.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('citas_modalidad_check', $exception->getMessage());
        }
        [$remision, $bloque] = $this->escenario();
        $this->citaEps($remision, $bloque, ['estado' => 'CANCELADA']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conservar las citas EPS');
        $migracion->down();
    }

    public function test_seeder_habilita_permiso_independiente_y_es_idempotente(): void
    {
        $otro = $this->actor(['RF-024']);
        $this->seed(CitaEpsPermissionsSeeder::class);
        $this->seed(CitaEpsPermissionsSeeder::class);

        $permiso = Permiso::query()->where('codigo', 'RF-025')->sole();
        $this->assertSame(['Administrador', 'Administrativo', 'Médico'], $permiso->roles()->orderBy('nombre')->pluck('nombre')->all());
        $this->assertFalse($otro->tienePermiso('RF-025'));
        $this->assertTrue($otro->tienePermiso('RF-024'));
    }
}
