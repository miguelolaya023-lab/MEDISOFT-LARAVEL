<?php

namespace Tests\Feature;

use App\Http\Requests\SaveDisponibilidadRequest;
use App\Models\Auditoria;
use App\Models\DisponibilidadMedica;
use App\Models\Medico;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\DisponibilidadPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Js;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DisponibilidadMedicaTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing') || config('database.default') !== 'sqlite') {
            throw new LogicException('Se requiere SQLite en memoria.');
        }
        foreach ($this->connectionsToTransact() as $name) {
            $config = DB::connection($name)->getConfig();
            if ($config['driver'] !== 'sqlite' || $config['database'] !== ':memory:' || ! empty($config['url'])) {
                throw new LogicException('Conexión no aislada.');
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-26 00:30:00', 'UTC'));
    }

    private function actor(bool $permiso = true, bool $perfil = true, array $datos = []): User
    {
        $rol = Rol::query()->create(['nombre' => 'Disponibilidad ficticia '.uniqid()]);
        if ($permiso) {
            $rol->permisos()->attach(Permiso::query()->firstOrCreate(['codigo' => 'RF-060'], ['descripcion' => 'Permiso ficticio'])->id);
        }
        $actor = User::factory()->create(['rol_id' => $rol->id, ...$datos]);
        if ($perfil) {
            $actor->medico()->create(['registro_profesional' => 'DEMO-'.uniqid(), 'especialidad' => 'Medicina general']);
        }

        return $actor->refresh();
    }

    private function bloque(User $actor, array $cambios = []): DisponibilidadMedica
    {
        return DisponibilidadMedica::factory()->create(['medico_id' => $actor->medico->id, 'fecha' => '2026-10-27', ...$cambios])->refresh();
    }

    private function datos(array $cambios = []): array
    {
        return ['fechas' => ['2026-10-27'], 'hora_inicio' => '08:00', 'hora_fin' => '10:00', 'duracion_minutos' => 30, 'confirmacion' => '1', ...$cambios];
    }

    private function edicion(array $cambios = []): array
    {
        return ['fecha' => '2026-10-27', 'hora_inicio' => '08:00', 'hora_fin' => '10:00', 'duracion_minutos' => 30, 'confirmacion' => '1', ...$cambios];
    }

    public static function operacionesBloqueo(): array
    {
        return [['registro'], ['edicion'], ['multifecha']];
    }

    /** Verifica intención y orden de locks; SQLite no ejecuta bloqueos FOR UPDATE. */
    #[DataProvider('operacionesBloqueo')]
    public function test_resource_mutex_precedes_snapshot_reads_and_overlap_query_never_locks_a_range(string $operacion): void
    {
        $actor = $this->actor();
        $bloque = $operacion === 'edicion' ? $this->bloque($actor) : null;
        $this->actingAs($actor);
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $nivelExterior = $db->transactionLevel();
        $consultas = [];
        $db->setQueryGrammar(new class($db) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, mixed $value): string
            {
                return $value === true ? '/* RF060_FOR_UPDATE_REQUESTED */' : '';
            }
        });
        DB::listen(static function (QueryExecuted $query) use ($db, $nivelExterior, &$consultas): void {
            if ($db->transactionLevel() > $nivelExterior && str_starts_with(strtolower($query->sql), 'select')) {
                $consultas[] = $query->sql;
            }
        });
        try {
            if ($operacion === 'edicion') {
                $this->put(route('disponibilidad.update', $bloque), $this->edicion(['hora_fin' => '11:00']))->assertSessionHasNoErrors();
            } else {
                $this->post(route('disponibilidad.store'), $this->datos(['fechas' => $operacion === 'multifecha' ? ['2026-10-27', '2026-10-28'] : ['2026-10-27']]))->assertSessionHasNoErrors();
            }
        } finally {
            $db->setQueryGrammar($original);
        }
        $this->assertStringContainsString('from "users"', $consultas[0]);
        $this->assertStringContainsString('RF060_FOR_UPDATE_REQUESTED', $consultas[0]);
        $this->assertStringContainsString('from "medicos"', $consultas[1]);
        $this->assertStringContainsString('RF060_FOR_UPDATE_REQUESTED', $consultas[1]);
        $this->assertStringContainsString('from "medicos"', $consultas[2]);
        $this->assertStringNotContainsString('RF060_FOR_UPDATE_REQUESTED', $consultas[2]);
        $rangos = array_values(array_filter($consultas, fn (string $sql) => str_contains($sql, 'from "disponibilidades_medicas"') && str_contains($sql, '"hora_inicio" <')));
        $this->assertCount($operacion === 'multifecha' ? 2 : 1, $rangos);
        foreach ($rangos as $sql) {
            $this->assertStringNotContainsString('RF060_FOR_UPDATE_REQUESTED', $sql);
        }
        $locks = array_values(array_filter($consultas, fn (string $sql) => str_contains($sql, 'RF060_FOR_UPDATE_REQUESTED')));
        $this->assertCount($operacion === 'edicion' ? 3 : 2, $locks);
        if ($operacion === 'edicion') {
            $this->assertStringContainsString('from "disponibilidades_medicas"', $locks[2]);
            $this->assertStringNotContainsString('"hora_inicio" <', $locks[2]);
        }
        $this->assertSame($nivelExterior, $db->transactionLevel());
    }

    public function test_ca06001_registers_four_intervals_without_appointments_and_with_safe_atomic_audit(): void
    {
        $actor = $this->actor();
        $cuenta = $actor->getAttributes();
        $perfil = $actor->medico->getAttributes();
        $this->actingAs($actor)->get(route('disponibilidad.index'))->assertOk()->assertSee('No hay bloques');
        $this->post(route('disponibilidad.store'), $this->datos())->assertSessionHasNoErrors()->assertRedirect(route('disponibilidad.index'));
        $bloque = DisponibilidadMedica::query()->sole();
        $this->assertSame($actor->medico->id, $bloque->medico_id);
        $this->assertSame([
            ['inicio' => '08:00', 'fin' => '08:30'], ['inicio' => '08:30', 'fin' => '09:00'],
            ['inicio' => '09:00', 'fin' => '09:30'], ['inicio' => '09:30', 'fin' => '10:00'],
        ], $bloque->intervalos());
        $this->assertSame($perfil, $actor->medico->refresh()->getAttributes());
        $this->assertSame($cuenta, $actor->refresh()->getAttributes());
        $this->assertDatabaseCount('pacientes', 0);
        $this->assertFalse(Schema::hasTable('citas'));
        $audit = Auditoria::query()->sole();
        $this->assertSame('DISPONIBILIDAD_REGISTRADA', $audit->accion);
        $this->assertSame('DisponibilidadMedica', $audit->entidad);
        $this->assertSame($bloque->id, $audit->registro_id);
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame('EXITO', $audit->resultado);
        $this->assertSame(now()->toDateTimeString(), $audit->fecha_hora->toDateTimeString());
        $this->assertStringContainsString('2026-10-27', $audit->descripcion);
        $this->assertStringNotContainsString($actor->password, $audit->toJson());
        $this->assertStringNotContainsString($actor->medico->registro_profesional, $audit->toJson());
        $this->get(route('disponibilidad.index'))->assertOk()->assertSee('08:00–08:30')->assertSee('09:30–10:00')->assertSee('Editar bloque');
    }

    public static function intervalosPermitidos(): array
    {
        return [
            ['08:00', '09:10', 30, 2, '09:00'],
            ['08:00', '08:01', 1, 1, '08:01'],
            ['13:15', '14:00', 15, 3, '14:00'],
            ['08:00', '10:00', 120, 1, '10:00'],
        ];
    }

    #[DataProvider('intervalosPermitidos')]
    public function test_duration_is_configurable_and_remainder_never_becomes_a_short_slot(string $inicio, string $fin, int $duracion, int $total, string $ultimoFin): void
    {
        $this->actingAs($this->actor())->post(route('disponibilidad.store'), $this->datos(['hora_inicio' => $inicio, 'hora_fin' => $fin, 'duracion_minutos' => $duracion]))->assertSessionHasNoErrors();
        $intervalos = DisponibilidadMedica::query()->sole()->intervalos();
        $this->assertCount($total, $intervalos);
        $this->assertSame($ultimoFin, $intervalos[array_key_last($intervalos)]['fin']);
        foreach ($intervalos as $intervalo) {
            $this->assertSame($duracion, DisponibilidadMedica::minutos($intervalo['fin']) - DisponibilidadMedica::minutos($intervalo['inicio']));
        }
    }

    public function test_multiple_calendar_dates_are_saved_atomically_and_ordered_without_recurrence_or_patient(): void
    {
        $this->actingAs($this->actor())->post(route('disponibilidad.store'), $this->datos(['fechas' => ['2026-10-29', '2026-10-27']]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('disponibilidades_medicas', 2);
        $this->assertDatabaseCount('auditorias', 2);
        $this->get(route('disponibilidad.index'))->assertSeeInOrder(['2026-10-27', '2026-10-29']);
        $this->assertSame('2026-10-25', DisponibilidadMedica::factory()->make()->fecha->toDateString());
    }

    public static function invalidos(): array
    {
        return [
            ['hora_inicio', null], ['hora_inicio', []], ['hora_inicio', '24:00'], ['hora_inicio', '8:00'],
            ['hora_inicio', "08:\0"], ['hora_fin', '08:00'], ['hora_fin', '07:59'], ['hora_fin', '00:30'],
            ['hora_fin', null], ['hora_fin', '25:00'], ['hora_fin', []],
            ['duracion_minutos', null], ['duracion_minutos', 0], ['duracion_minutos', -1],
            ['duracion_minutos', 121], ['duracion_minutos', 1.5], ['duracion_minutos', []],
            ['duracion_minutos', true], ['duracion_minutos', 'abc'], ['confirmacion', null], ['confirmacion', '0'],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_invalid_hours_ranges_durations_and_confirmation_reject_store_and_update_without_writes(string $campo, mixed $valor): void
    {
        $actor = $this->actor();
        $bloque = $this->bloque($actor);
        $antes = $bloque->getAttributes();
        $this->actingAs($actor)->postJson(route('disponibilidad.store'), $this->datos([$campo => $valor]))->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->putJson(route('disponibilidad.update', $bloque), $this->edicion([$campo => $valor]))->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->assertSame($antes, $bloque->refresh()->getAttributes());
        $this->assertDatabaseCount('disponibilidades_medicas', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function fechasInvalidas(): array
    {
        return [[null], [[]], ['2026-10-27'], [['2026-02-30']], [['2026-10-27', '2026-10-27']], [[['2026-10-27']]], [['x' => '2026-10-27']], [["2026-10\0-27"]]];
    }

    #[DataProvider('fechasInvalidas')]
    public function test_invalid_or_duplicate_dates_cannot_create_partial_blocks(mixed $fechas): void
    {
        $response = $this->actingAs($this->actor())->postJson(route('disponibilidad.store'), $this->datos(['fechas' => $fechas]))->assertUnprocessable();
        $this->assertNotEmpty($response->json('errors'));
        $this->assertDatabaseCount('disponibilidades_medicas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function fechasEdicionInvalidas(): array
    {
        return [[null], [[]], ['2026-02-30'], ['27/10/2026'], ["2026-10\0-27"]];
    }

    #[DataProvider('fechasEdicionInvalidas')]
    public function test_invalid_edit_date_is_rejected(mixed $fecha): void
    {
        $actor = $this->actor();
        $bloque = $this->bloque($actor);
        $this->actingAs($actor)->putJson(route('disponibilidad.update', $bloque), $this->edicion(['fecha' => $fecha]))->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->assertSame('2026-10-27', $bloque->refresh()->fecha->toDateString());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function superpuestos(): array
    {
        return [['08:00', '10:00'], ['07:00', '08:01'], ['09:59', '11:00'], ['08:30', '09:30'], ['07:00', '11:00'], ['08:00', '09:00']];
    }

    #[DataProvider('superpuestos')]
    public function test_ca06002_rejects_all_overlap_shapes_for_same_doctor_date(string $inicio, string $fin): void
    {
        $actor = $this->actor();
        $this->bloque($actor);
        $this->actingAs($actor)->post(route('disponibilidad.store'), $this->datos(['hora_inicio' => $inicio, 'hora_fin' => $fin, 'duracion_minutos' => 1]))->assertInvalid('hora_inicio');
        $this->assertDatabaseCount('disponibilidades_medicas', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_adjacent_blocks_other_dates_and_other_doctors_do_not_overlap(): void
    {
        $actor = $this->actor();
        $this->bloque($actor);
        $this->bloque($this->actor(), ['hora_inicio' => '10:00:00', 'hora_fin' => '12:00:00']);
        $this->actingAs($actor);
        foreach ([
            ['hora_inicio' => '10:00', 'hora_fin' => '12:00'],
            ['hora_inicio' => '07:00', 'hora_fin' => '08:00'],
            ['fechas' => ['2026-10-28']],
        ] as $cambio) {
            $this->post(route('disponibilidad.store'), $this->datos($cambio))->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('disponibilidades_medicas', 5);
        $this->assertDatabaseCount('auditorias', 3);
    }

    public function test_conflict_on_last_date_rolls_back_prior_blocks_and_audits(): void
    {
        $actor = $this->actor();
        $existente = $this->bloque($actor);
        $antes = $existente->getAttributes();
        $this->actingAs($actor)->post(route('disponibilidad.store'), $this->datos(['fechas' => ['2026-10-28', '2026-10-27']]))->assertInvalid('hora_inicio');
        $this->assertDatabaseCount('disponibilidades_medicas', 1);
        $this->assertDatabaseCount('auditorias', 0);
        $this->assertSame($antes, $existente->refresh()->getAttributes());
    }

    public function test_edit_excludes_itself_preserves_identity_and_records_only_actual_changes(): void
    {
        $actor = $this->actor();
        $bloque = $this->bloque($actor);
        $id = $bloque->id;
        $creado = $bloque->created_at->toDateTimeString();
        $this->actingAs($actor)->get(route('disponibilidad.edit', $bloque))->assertOk();
        $this->put(route('disponibilidad.update', $bloque), $this->edicion())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('auditorias', 0);
        $this->travel(1)->minutes();
        $this->patch(route('disponibilidad.update', $bloque), $this->edicion(['fecha' => '2026-10-28', 'hora_fin' => '09:10']))->assertSessionHasNoErrors()->assertRedirect(route('disponibilidad.index'));
        $this->assertSame($id, $bloque->refresh()->id);
        $this->assertSame($actor->medico->id, $bloque->medico_id);
        $this->assertSame($creado, $bloque->created_at->toDateTimeString());
        $this->assertCount(2, $bloque->intervalos());
        $audit = Auditoria::query()->sole();
        $this->assertSame('DISPONIBILIDAD_ACTUALIZADA', $audit->accion);
        $this->assertSame($id, $audit->registro_id);
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertStringContainsString('2026-10-28', $audit->descripcion);
    }

    public function test_edit_cannot_move_to_another_date_with_an_overlapping_block(): void
    {
        $actor = $this->actor();
        $bloque = $this->bloque($actor);
        $otro = $this->bloque($actor, ['fecha' => '2026-10-28']);
        $antes = $bloque->getAttributes();
        $otroAntes = $otro->getAttributes();
        $this->actingAs($actor)->put(route('disponibilidad.update', $bloque), $this->edicion(['fecha' => '2026-10-28']))->assertInvalid('hora_inicio');
        $this->assertSame($antes, $bloque->refresh()->getAttributes());
        $this->assertSame($otroAntes, $otro->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_create_form_restores_all_dates_and_other_values_after_validation_error(): void
    {
        $datos = $this->datos(['fechas' => ['2026-10-27', '2026-10-28'], 'hora_inicio' => '07:00', 'hora_fin' => '08:30', 'duracion_minutos' => 15, 'confirmacion' => '0']);
        $this->actingAs($this->actor())->post(route('disponibilidad.store'), $datos)->assertInvalid('confirmacion')->assertRedirect(route('disponibilidad.index'));
        $this->assertSame($datos['fechas'], session()->getOldInput('fechas'));
        $this->get(route('disponibilidad.index'))->assertOk()
            ->assertSee((string) Js::from($datos['fechas']), false)
            ->assertSee('x-model="fila.valor"', false)
            ->assertSee('quitarFecha(fila.id)', false)
            ->assertSee('value="07:00"', false)->assertSee('value="08:30"', false)->assertSee('value="15"', false)
            ->assertDontSee('type="hidden" name="fechas', false);
        $this->assertDatabaseCount('disponibilidades_medicas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_corrected_submission_only_persists_remaining_and_new_dates_after_validation_error(): void
    {
        $this->actingAs($this->actor())->post(route('disponibilidad.store'), $this->datos(['fechas' => ['2026-10-27', '2026-10-28'], 'confirmacion' => '0']))->assertInvalid('confirmacion');
        $this->get(route('disponibilidad.index'))->assertOk();
        $this->post(route('disponibilidad.store'), $this->datos(['fechas' => ['2026-10-28', '2026-10-29']]))->assertSessionHasNoErrors();
        $this->assertSame(['2026-10-28', '2026-10-29'], DisponibilidadMedica::query()->orderBy('fecha')->get()->map(fn (DisponibilidadMedica $bloque) => $bloque->fecha->toDateString())->all());
        $this->assertDatabaseCount('auditorias', 2);
    }

    public static function edicionesHttp(): array
    {
        return [['PUT', false], ['PATCH', false], ['PUT', true], ['PATCH', true]];
    }

    #[DataProvider('edicionesHttp')]
    public function test_expanding_into_adjacent_block_is_rejected_without_changes_or_additional_audits(string $method, bool $json): void
    {
        $actor = $this->actor();
        $a = $this->bloque($actor, ['hora_inicio' => '08:00:00', 'hora_fin' => '09:00:00']);
        $b = $this->bloque($actor, ['hora_inicio' => '09:00:00', 'hora_fin' => '10:00:00']);
        $antesA = $a->getAttributes();
        $antesB = $b->getAttributes();
        $auditorias = Auditoria::query()->get()->toArray();
        $this->actingAs($actor);
        $url = route('disponibilidad.update', $a);
        $datos = $this->edicion(['hora_fin' => '09:30']);
        $response = $json ? $this->json($method, $url, $datos) : $this->call($method, $url, $datos);
        $response->assertInvalid('hora_inicio')->assertSessionMissing('status');
        $this->assertSame($antesA, $a->refresh()->getAttributes());
        $this->assertSame($antesB, $b->refresh()->getAttributes());
        $this->assertDatabaseCount('disponibilidades_medicas', 2);
        $this->assertSame($auditorias, Auditoria::query()->get()->toArray());
    }

    public static function ampliacionesValidas(): array
    {
        return [['08:30:00', '08:45', 1], ['08:30:00', '09:00', 1], ['09:00:00', '09:00', 0]];
    }

    #[DataProvider('ampliacionesValidas')]
    public function test_edit_allows_free_extension_and_contiguous_boundary_excluding_itself(string $finOriginal, string $finNuevo, int $auditorias): void
    {
        $actor = $this->actor();
        $a = $this->bloque($actor, ['hora_inicio' => '08:00:00', 'hora_fin' => $finOriginal]);
        $b = $this->bloque($actor, ['hora_inicio' => '09:00:00', 'hora_fin' => '10:00:00']);
        $antesB = $b->getAttributes();
        $this->actingAs($actor)->put(route('disponibilidad.update', $a), $this->edicion(['hora_fin' => $finNuevo]))->assertSessionHasNoErrors()->assertRedirect(route('disponibilidad.index'));
        $this->assertSame($finNuevo.':00', $a->refresh()->hora_fin);
        $this->assertSame($antesB, $b->refresh()->getAttributes());
        $this->assertDatabaseCount('disponibilidades_medicas', 2);
        $this->assertDatabaseCount('auditorias', $auditorias);
        if ($auditorias === 1) {
            $this->assertSame($a->id, Auditoria::query()->sole()->registro_id);
            $this->assertSame('DISPONIBILIDAD_ACTUALIZADA', Auditoria::query()->sole()->accion);
        }
    }

    public static function manipulados(): array
    {
        return [['medico_id', 99999], ['user_id', 99999], ['id', 99999], ['estado', 'INACTIVO'], ['created_at', '2000-01-01'], ['rol_id', 1], ['permisos', ['RF-060']], ['cita_id', 1], ['dia_semana', 'lunes']];
    }

    #[DataProvider('manipulados')]
    public function test_forged_fields_are_rejected_on_store_and_update(string $campo, mixed $valor): void
    {
        $actor = $this->actor();
        $bloque = $this->bloque($actor);
        $antes = $bloque->getAttributes();
        $this->actingAs($actor)->postJson(route('disponibilidad.store'), $this->datos([$campo => $valor]))->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->putJson(route('disponibilidad.update', $bloque), $this->edicion([$campo => $valor]))->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->assertSame($antes, $bloque->refresh()->getAttributes());
        $this->assertDatabaseCount('disponibilidades_medicas', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function sinAutorizacion(): array
    {
        return [['sin_permiso'], ['sin_perfil'], ['sin_registro'], ['sin_especialidad'], ['cargo'], ['rol_administrador'], ['rol_medico']];
    }

    #[DataProvider('sinAutorizacion')]
    public function test_ca06004_all_routes_require_effective_permission_and_complete_professional_profile(string $caso): void
    {
        $actor = $this->actor($caso !== 'sin_permiso' && $caso !== 'cargo', $caso !== 'sin_perfil' && $caso !== 'rol_administrador' && $caso !== 'rol_medico', ['cargo' => 'Administrador']);
        if ($caso === 'sin_registro' || $caso === 'sin_especialidad') {
            $actor->medico()->update([$caso === 'sin_registro' ? 'registro_profesional' : 'especialidad' => '   ']);
        }
        if ($caso === 'rol_administrador' || $caso === 'rol_medico') {
            $actor->rol()->update(['nombre' => $caso === 'rol_administrador' ? 'Administrador' : 'Médico']);
        }
        $bloque = $this->bloque($this->actor());
        $antes = $bloque->getAttributes();
        $this->actingAs($actor);
        foreach ([
            ['GET', route('disponibilidad.index'), []],
            ['POST', route('disponibilidad.store'), $this->datos()],
            ['GET', route('disponibilidad.edit', $bloque), []],
            ['PUT', route('disponibilidad.update', $bloque), $this->edicion()],
        ] as [$method, $url, $datos]) {
            $this->call($method, $url, $datos)->assertForbidden()->assertDontSee('2026-10-27');
        }
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Mi disponibilidad');
        $this->assertSame($antes, $bloque->refresh()->getAttributes());
        $this->assertDatabaseCount('disponibilidades_medicas', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_administrator_with_profile_and_permission_can_only_configure_own_profile(): void
    {
        $actor = $this->actor();
        $actor->rol()->update(['nombre' => 'Administrador']);
        $ajeno = $this->bloque($this->actor(), ['fecha' => '2026-12-15']);
        $this->actingAs($actor)->get(route('disponibilidad.index'))->assertOk()->assertDontSee('2026-12-15');
        $this->get(route('disponibilidad.edit', $ajeno))->assertForbidden();
        $this->put(route('disponibilidad.update', $ajeno), $this->edicion())->assertForbidden();
        $this->post(route('disponibilidad.store'), $this->datos())->assertSessionHasNoErrors();
        $this->assertSame('2026-10-27', $actor->medico->disponibilidades()->sole()->fecha->toDateString());
        $this->assertSame('2026-12-15', $ajeno->refresh()->fecha->toDateString());
        $this->assertDatabaseCount('auditorias', 1);
    }

    #[DataProvider('sesiones')]
    public function test_guest_or_inactive_session_cannot_read_or_write(bool $inactivo): void
    {
        $actor = $this->actor();
        $bloque = $this->bloque($actor);
        if ($inactivo) {
            $actor->forceFill(['estado' => 'inactivo'])->save();
        }
        foreach ([
            ['GET', route('disponibilidad.index'), []],
            ['POST', route('disponibilidad.store'), $this->datos()],
            ['GET', route('disponibilidad.edit', $bloque), []],
            ['PATCH', route('disponibilidad.update', $bloque), $this->edicion()],
        ] as [$method, $url, $datos]) {
            if ($inactivo) {
                $this->actingAs($actor);
            }
            $this->call($method, $url, $datos)->assertRedirect(route('login'));
        }
        $this->assertDatabaseCount('disponibilidades_medicas', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function sesiones(): array
    {
        return [[false], [true]];
    }

    public function test_missing_blocks_and_unsupported_delete_have_no_effect(): void
    {
        $this->actingAs($this->actor())->get(route('disponibilidad.edit', 999999))->assertNotFound();
        $this->put(route('disponibilidad.update', 999999), $this->edicion())->assertNotFound();
        $this->delete('/disponibilidad/999999')->assertStatus(405);
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('fallosAuditoria')]
    public function test_audit_failure_rolls_back_all_dates_or_edit(string $operacion): void
    {
        $actor = $this->actor();
        $bloque = $this->bloque($actor);
        $antes = $bloque->getAttributes();
        $this->actingAs($actor);
        $this->withoutExceptionHandling();
        $numero = 0;
        Auditoria::created(static function () use (&$numero, $operacion): void {
            $numero++;
            if ($operacion !== 'segunda_fecha' || $numero === 2) {
                throw new RuntimeException('Fallo de auditoría ficticio');
            }
        });
        try {
            try {
                if ($operacion === 'edicion') {
                    $this->put(route('disponibilidad.update', $bloque), $this->edicion(['hora_fin' => '11:00']));
                } else {
                    $this->post(route('disponibilidad.store'), $this->datos(['fechas' => ['2026-10-28', '2026-10-29']]));
                }
                $this->fail('Debe propagarse el fallo de auditoría.');
            } catch (RuntimeException $e) {
                $this->assertSame('Fallo de auditoría ficticio', $e->getMessage());
            }
        } finally {
            Auditoria::flushEventListeners();
        }
        $this->assertSame($antes, $bloque->refresh()->getAttributes());
        $this->assertDatabaseCount('disponibilidades_medicas', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function fallosAuditoria(): array
    {
        return [['registro'], ['segunda_fecha'], ['edicion']];
    }

    public static function revocaciones(): array
    {
        return [['perfil', false], ['perfil', true], ['permiso', false], ['permiso', true], ['cuenta', false], ['cuenta', true]];
    }

    #[DataProvider('revocaciones')]
    public function test_authorization_changed_after_validation_is_rechecked_inside_transaction(string $caso, bool $editar): void
    {
        $actor = $this->actor();
        $bloque = $this->bloque($actor);
        $antes = $bloque->getAttributes();
        $this->actingAs($actor);
        $this->app->afterResolving(SaveDisponibilidadRequest::class, static function () use ($actor, $caso): void {
            match ($caso) {
                'perfil' => $actor->medico()->update(['registro_profesional' => '']),
                'permiso' => $actor->rol->permisos()->detach(),
                'cuenta' => $actor->update(['estado' => 'inactivo']),
            };
        });
        if ($editar) {
            $this->put(route('disponibilidad.update', $bloque), $this->edicion(['hora_fin' => '11:00']))->assertForbidden();
        } else {
            $this->post(route('disponibilidad.store'), $this->datos(['fechas' => ['2026-10-28']]))->assertForbidden();
        }
        $this->assertSame($antes, $bloque->refresh()->getAttributes());
        $this->assertDatabaseCount('disponibilidades_medicas', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_proposed_seeder_is_idempotent_preserves_existing_assignments_and_requires_profile_separately(): void
    {
        $otro = Permiso::query()->create(['codigo' => 'RF-058', 'descripcion' => 'Ficticio']);
        $rol = Rol::query()->create(['nombre' => 'Administrador']);
        $rol->permisos()->attach($otro);
        $administrativo = Rol::query()->create(['nombre' => 'Administrativo']);
        $this->seed(DisponibilidadPermissionsSeeder::class);
        $this->seed(DisponibilidadPermissionsSeeder::class);
        $this->assertSame(2, $rol->permisos()->count());
        $this->assertSame(['RF-060'], Rol::query()->where('nombre', 'Médico')->firstOrFail()->permisos()->pluck('codigo')->all());
        $this->assertSame(0, $administrativo->permisos()->count());
        $user = User::factory()->create(['rol_id' => $rol->id]);
        $this->assertFalse($user->can('create', DisponibilidadMedica::class));
    }

    public function test_foreign_key_and_unique_index_reject_orphans_duplicate_starts_and_profile_deletion(): void
    {
        $actor = $this->actor();
        $bloque = $this->bloque($actor);
        foreach ([
            fn () => DisponibilidadMedica::factory()->create(['medico_id' => 999999]),
            fn () => DisponibilidadMedica::factory()->create(['medico_id' => $bloque->medico_id, 'fecha' => '2026-10-27']),
            fn () => $actor->medico()->delete(),
        ] as $operacion) {
            try {
                $operacion();
                $this->fail('Debe rechazarse por integridad.');
            } catch (QueryException) {
                $this->assertDatabaseCount('disponibilidades_medicas', 1);
            }
        }
        $this->assertSame(1, Medico::query()->count());
    }

    public function test_mysql_schema_compiles_incremental_innodb_foreign_key_and_index_without_connecting(): void
    {
        $connection = new class(static function (): never {
            throw new LogicException('No se permite conexión MySQL.');
        }, 'preview', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']) extends MySqlConnection {

            public function getServerVersion(): string
            {
                return '8.0.46';
            }

            public function isMaria(): bool
            {
                return false;
            }
        };
        $original = Schema::getFacadeRoot();
        Schema::swap($connection->getSchemaBuilder());
        try {
            $queries = $connection->pretend(static function (): void {
                (require database_path('migrations/2026_10_26_011632_create_disponibilidades_medicas_table.php'))->up();
            });
            $sql = implode("\n", array_column($queries, 'query'));
            $this->assertStringContainsString('engine = InnoDB', $sql);
            $this->assertStringContainsString('(`medico_id`, `fecha`, `hora_inicio`)', $sql);
            $this->assertStringContainsString('references `medicos` (`id`) on delete restrict on update restrict', $sql);
            $this->assertStringContainsString('smallint unsigned', $sql);
            $this->assertStringNotContainsString('drop ', $sql);
            $this->assertStringNotContainsString('alter table `medicos`', $sql);
        } finally {
            Schema::swap($original);
        }
    }
}
