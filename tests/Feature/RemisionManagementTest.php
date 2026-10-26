<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Eps;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RemisionPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RemisionManagementTest extends TestCase
{
    use RefreshDatabase;

    private Paciente $paciente;

    private Eps $eps;

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
        $this->travelTo(Carbon::parse('2026-10-25 10:00:00', 'America/Bogota'));
        $this->paciente = Paciente::factory()->create();
        $this->eps = Eps::factory()->create();
    }

    private function operador(array $codes = ['RF-019', 'RF-020', 'RF-021', 'RF-022', 'RF-023']): User
    {
        $rol = Rol::query()->create(['nombre' => 'Remisiones prueba '.uniqid()]);
        foreach ($codes as $code) {
            $rol->permisos()->syncWithoutDetaching([Permiso::query()->firstOrCreate(['codigo' => $code], ['descripcion' => 'Permiso ficticio'])->id]);
        }

        return User::factory()->create(['rol_id' => $rol->id]);
    }

    private function data(array $changes = []): array
    {
        return ['paciente_id' => $this->paciente->id, 'eps_id' => $this->eps->id, 'numero_remision' => '001-DEMO',
            'fecha_remision' => '2026-10-25', 'vigencia_hasta' => '2026-11-25', 'servicio_autorizado' => 'SERVICIO_FICTICIO_RESERVADO', 'observacion' => 'OBSERVACION_FICTICIA_RESERVADA', ...$changes];
    }

    private function remision(array $changes = []): RemisionEps
    {
        return RemisionEps::factory()->create(['paciente_id' => $this->paciente->id, 'eps_id' => $this->eps->id, ...$changes])->refresh();
    }

    private function editData(RemisionEps $r, array $changes = []): array
    {
        return ['servicio_autorizado' => $r->servicio_autorizado, 'vigencia_hasta' => $r->vigencia_hasta->toDateString(), 'observacion' => 'Observación corregida ficticia', ...$changes];
    }

    public static function initialStates(): array
    {
        return [['2026-11-25', 'VIGENTE'], ['2026-10-25', 'VIGENTE'], ['2026-10-24', 'VENCIDA']];
    }

    #[DataProvider('initialStates')]
    public function test_registration_links_active_patient_and_eps_with_server_state_and_safe_audit(string $until, string $state): void
    {
        $this->actingAs($this->operador());
        $this->get(route('remisiones.create'))->assertOk();
        $this->post(route('remisiones.store'), $this->data(['fecha_remision' => '2026-10-01', 'vigencia_hasta' => $until, 'numero_remision' => ' 001-DEMO ']))->assertSessionHasNoErrors();
        $r = RemisionEps::query()->sole();
        $this->assertSame('001-DEMO', $r->numero_remision);
        $this->assertSame($state, $r->estado);
        $this->assertSame($this->paciente->id, $r->paciente->id);
        $this->assertSame($this->eps->id, $r->eps->id);
        $this->assertSame($r->id, $this->paciente->remisiones()->sole()->id);
        $this->assertSame($r->id, $this->eps->remisiones()->sole()->id);
        $this->assertNotNull($r->created_at);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('historias_clinicas', 0);
        $audit = Auditoria::query()->sole();
        $this->assertSame('REMISION_CREADA', $audit->accion);
        $this->assertStringNotContainsString('RESERVADO', json_encode($audit->toArray()));
        $this->assertSame('RemisionEPS', $audit->entidad);
        $this->assertSame($r->id, $audit->registro_id);
        $this->get(route('remisiones.show', $r))->assertSee($r->numero_remision)->assertSee($state);
    }

    public function test_number_unique_per_eps_excludes_other_eps_and_preserves_leading_zeroes(): void
    {
        $this->actingAs($this->operador());
        $this->post(route('remisiones.store'), $this->data())->assertSessionHasNoErrors();
        $this->post(route('remisiones.store'), $this->data(['numero_remision' => ' 001-DEMO ']))->assertInvalid('numero_remision');
        $other = Eps::factory()->create();
        $this->post(route('remisiones.store'), $this->data(['eps_id' => $other->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('remisiones_eps', 2);
        try {
            $this->remision(['numero_remision' => '001-DEMO']);
            $this->fail('Debe proteger la pareja EPS+número en BD.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('remisiones_eps', 2);
        }
    }

    public static function requiredFields(): array
    {
        return array_map(fn ($field) => [$field], ['paciente_id', 'eps_id', 'numero_remision', 'fecha_remision', 'servicio_autorizado', 'vigencia_hasta']);
    }

    #[DataProvider('requiredFields')]
    public function test_registration_required_fields_have_no_partial_writes(string $field): void
    {
        $data = $this->data();
        unset($data[$field]);
        $this->actingAs($this->operador())->post(route('remisiones.store'), $data)->assertInvalid($field);
        $this->assertDatabaseCount('remisiones_eps', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function invalidFields(): array
    {
        return [['paciente_id', 99999], ['eps_id', 99999], ['paciente_id', []], ['eps_id', []], ['numero_remision', []], ['numero_remision', str_repeat('a', 101)], ['fecha_remision', []], ['fecha_remision', "2026-10\0-25"], ['vigencia_hasta', "2026-10\0-25"], ['fecha_remision', '2026-02-30'], ['fecha_remision', '2026-10-26'], ['vigencia_hasta', '2026-10-24'], ['vigencia_hasta', []], ['servicio_autorizado', ' '], ['observacion', []]];
    }

    #[DataProvider('invalidFields')]
    public function test_registration_rejects_invalid_fields(string $field, mixed $value): void
    {
        $this->actingAs($this->operador())->post(route('remisiones.store'), $this->data([$field => $value]))->assertInvalid($field);
        $this->assertDatabaseCount('remisiones_eps', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function forgedFields(): array
    {
        return [['estado', 'USADA'], ['id', 99], ['created_at', '2000-01-01'], ['user_id', null], ['permisos', []], ['motivo_cambio_estado', 'Ficticio']];
    }

    #[DataProvider('forgedFields')]
    public function test_forged_fields_cannot_bypass_store_or_update(string $field, mixed $value): void
    {
        $r = $this->remision();
        $before = $r->getAttributes();
        $this->actingAs($this->operador())->post(route('remisiones.store'), $this->data([$field => $value]))->assertInvalid($field);
        $this->put(route('remisiones.update', $r), $this->editData($r, [$field => $value]))->assertInvalid($field);
        $this->assertSame($before, $r->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function inactiveParents(): array
    {
        return [['paciente_id'], ['eps_id']];
    }

    #[DataProvider('inactiveParents')]
    public function test_inactive_parents_rejected_inside_transaction_even_when_changed_after_validation(string $field): void
    {
        $this->actingAs($this->operador());
        $injected = false;
        DB::listen(function (QueryExecuted $query) use ($field, &$injected): void {
            if (! $injected && str_contains($query->sql, 'count(') && str_contains($query->sql, '"remisiones_eps"')) {
                $injected = true;
                if ($field === 'eps_id') {
                    $this->eps->update(['estado' => 'INACTIVA']);
                } else {
                    $this->paciente->forceFill(['estado' => 'INACTIVO'])->save();
                }
            }
        });
        $this->post(route('remisiones.store'), $this->data())->assertInvalid($field);
        $this->assertTrue($injected);
        $this->assertDatabaseCount('remisiones_eps', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_uniqueness_race_after_validation_is_a_controlled_error(): void
    {
        $this->actingAs($this->operador());
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if (! $injected && str_contains($query->sql, 'count(') && str_contains($query->sql, '"remisiones_eps"')) {
                $injected = true;
                $this->remision(['numero_remision' => '001-DEMO']);
            }
        });
        $this->post(route('remisiones.store'), $this->data())->assertInvalid('numero_remision');
        $this->assertTrue($injected);
        $this->assertDatabaseCount('remisiones_eps', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_update_preserves_identity_dates_and_historical_references_with_inactive_parents(): void
    {
        $r = $this->remision();
        $identity = $r->only(['id', 'paciente_id', 'eps_id', 'numero_remision', 'fecha_remision', 'created_at']);
        $this->paciente->forceFill(['estado' => 'INACTIVO'])->save();
        $this->eps->update(['estado' => 'INACTIVA']);
        $this->actingAs($this->operador(['RF-020']))->get(route('remisiones.edit', $r))->assertOk();
        $this->put(route('remisiones.update', $r), $this->editData($r))->assertSessionHasNoErrors()->assertRedirect(route('remisiones.edit', $r));
        $this->assertEquals($identity, $r->refresh()->only(array_keys($identity)));
        foreach (['paciente_id' => 999, 'eps_id' => 999, 'numero_remision' => 'CAMBIO', 'fecha_remision' => '2000-01-01'] as $field => $value) {
            $this->put(route('remisiones.update', $r), $this->editData($r, [$field => $value]))->assertInvalid($field);
        }
        $this->assertSame('Observación corregida ficticia', $r->refresh()->observacion);
    }

    public function test_expired_validity_can_only_be_corrected_with_reason_and_preserves_each_reason(): void
    {
        $r = $this->remision(['fecha_remision' => '2026-10-01', 'vigencia_hasta' => '2026-10-24', 'estado' => 'VENCIDA']);
        $this->actingAs($this->operador());
        $this->put(route('remisiones.update', $r), $this->editData($r, ['vigencia_hasta' => '2026-11-01']))->assertInvalid('motivo');
        $this->put(route('remisiones.update', $r), $this->editData($r, ['vigencia_hasta' => '2026-11-01', 'motivo' => 'JUSTIFICACION_FICTICIA_RESERVADA']))->assertSessionHasNoErrors();
        $this->assertSame('VIGENTE', $r->refresh()->estado);
        $this->patch(route('remisiones.changeState', $r), ['estado' => 'ANULADA', 'motivo' => 'ANULACION_FICTICIA_RESERVADA', 'confirmacion' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('ANULADA', $r->refresh()->estado);
        $this->assertSame(['JUSTIFICACION_FICTICIA_RESERVADA', 'ANULACION_FICTICIA_RESERVADA'], $r->cambiosEstado()->pluck('motivo')->all());
        $this->assertStringNotContainsString('RESERVAD', Auditoria::query()->get()->toJson());
        $this->get(route('remisiones.show', $r))->assertSee('JUSTIFICACION_FICTICIA_RESERVADA')->assertSee('ANULACION_FICTICIA_RESERVADA')->assertDontSee('Anular remisión');
    }

    public function test_used_remission_only_accepts_observation_and_cannot_be_reactivated_or_cancelled(): void
    {
        $r = $this->remision(['estado' => 'USADA']);
        $this->actingAs($this->operador());
        $this->put(route('remisiones.update', $r), $this->editData($r))->assertSessionHasNoErrors();
        $this->put(route('remisiones.update', $r), $this->editData($r, ['servicio_autorizado' => 'Cambio indebido']))->assertInvalid('servicio_autorizado')->assertValid('vigencia_hasta');
        foreach (['VIGENTE', 'USADA', 'ANULADA'] as $state) {
            $this->patch(route('remisiones.changeState', $r), ['estado' => $state, 'motivo' => 'Ficticio', 'confirmacion' => '1'])->assertInvalid('estado');
        }
        $this->assertSame('USADA', $r->refresh()->estado);
    }

    public static function usedRemissionChanges(): array
    {
        $cases = [];
        $changes = [
            'servicio' => [['servicio_autorizado' => 'Servicio ficticio manipulado'], ['servicio_autorizado']],
            'vigencia' => [['vigencia_hasta' => '2026-11-26'], ['vigencia_hasta']],
            'ambos' => [['servicio_autorizado' => 'Servicio ficticio manipulado', 'vigencia_hasta' => '2026-11-26'], ['servicio_autorizado', 'vigencia_hasta']],
        ];
        foreach ($changes as $name => [$data, $errors]) {
            foreach (['PUT', 'PATCH'] as $method) {
                foreach ([false, true] as $json) {
                    $cases[$name.'-'.$method.'-'.($json ? 'json' : 'html')] = [$data, $errors, $method, $json];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('usedRemissionChanges')]
    public function test_used_remission_returns_exact_errors_for_service_validity_or_both_without_writes(array $changes, array $expectedErrors, string $method, bool $json): void
    {
        $r = $this->remision(['estado' => 'USADA']);
        $before = $r->getAttributes();
        $this->actingAs($this->operador(['RF-020']))->from(route('remisiones.edit', $r));
        $url = route('remisiones.update', $r);
        $data = $this->editData($r, $changes);
        $response = $json ? $this->json($method, $url, $data) : $this->call($method, $url, $data);
        $response->assertInvalid($expectedErrors);
        if ($json) {
            $response->assertUnprocessable();
            $errors = $response->json('errors');
        } else {
            $response->assertRedirect(route('remisiones.edit', $r));
            $errors = $this->app['session']->get('errors')->getBag('default')->messages();
        }
        $this->assertSame($expectedErrors, array_keys($errors));
        if (isset($errors['servicio_autorizado'])) {
            $this->assertStringContainsString('el servicio', $errors['servicio_autorizado'][0]);
        }
        if (isset($errors['vigencia_hasta'])) {
            $this->assertStringContainsString('la vigencia', $errors['vigencia_hasta'][0]);
        }
        $this->assertSame($before, $r->refresh()->getAttributes());
        $this->assertDatabaseCount('remisiones_eps', 1);
        $this->assertDatabaseCount('cambios_estado_remision', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function forgedUsedRemissionFields(): array
    {
        return [['paciente_id', 99999], ['eps_id', 99999], ['numero_remision', 'NUMERO-FORJADO'], ['estado', 'VIGENTE'], ['fecha_remision', '2020-01-01'], ['servicio', 'ALIAS-FORJADO']];
    }

    #[DataProvider('forgedUsedRemissionFields')]
    public function test_used_remission_rejects_forged_identity_state_and_service_alias(string $field, mixed $value): void
    {
        $r = $this->remision(['estado' => 'USADA']);
        $before = $r->getAttributes();
        $this->actingAs($this->operador(['RF-020']))
            ->putJson(route('remisiones.update', $r), $this->editData($r, [$field => $value]))
            ->assertUnprocessable()->assertInvalid($field);
        $this->assertSame($before, $r->refresh()->getAttributes());
        $this->assertDatabaseCount('cambios_estado_remision', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_user_with_exclusively_rf019_can_register_but_cannot_edit_read_list_or_cancel(): void
    {
        $user = $this->operador(['RF-019']);
        $this->assertTrue($user->estaActivo());
        $this->assertTrue($user->esInterno());
        $this->assertSame(['RF-019'], $user->rol->permisos()->pluck('codigo')->all());
        $this->assertTrue($user->tienePermiso('RF-019'));
        foreach (['RF-020', 'RF-021', 'RF-022', 'RF-023'] as $code) {
            $this->assertFalse($user->tienePermiso($code));
        }
        $this->actingAs($user)->get(route('remisiones.create'))->assertOk();
        $this->post(route('remisiones.store'), $this->data())
            ->assertSessionHasNoErrors()->assertRedirect(route('remisiones.create'))->assertSessionHas('status', 'Remisión registrada correctamente.');
        $r = RemisionEps::query()->sole()->refresh();
        $this->assertSame('001-DEMO', $r->numero_remision);
        $this->assertSame('VIGENTE', $r->estado);
        $this->assertSame($this->paciente->id, $r->paciente_id);
        $this->assertSame($this->eps->id, $r->eps_id);
        $audit = Auditoria::query()->sole();
        $this->assertSame('REMISION_CREADA', $audit->accion);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('EXITO', $audit->resultado);
        $before = $r->getAttributes();
        $audits = Auditoria::query()->get()->toArray();
        $pacienteBefore = $this->paciente->refresh()->getAttributes();
        $epsBefore = $this->eps->refresh()->getAttributes();
        $operations = [
            ['GET', route('remisiones.index'), []],
            ['GET', route('remisiones.search', ['buscar' => $r->numero_remision]), []],
            ['GET', route('remisiones.show', $r), []],
            ['GET', route('remisiones.edit', $r), []],
            ['GET', route('remisiones.state', $r), []],
            ['PUT', route('remisiones.update', $r), $this->editData($r)],
            ['PATCH', route('remisiones.update', $r), $this->editData($r)],
            ['PATCH', route('remisiones.changeState', $r), ['estado' => 'ANULADA', 'motivo' => 'Intento ficticio no autorizado', 'confirmacion' => '1']],
        ];
        foreach ($operations as [$method, $url, $data]) {
            $this->call($method, $url, $data)->assertForbidden()->assertDontSee($r->servicio_autorizado)->assertDontSee($r->observacion);
            $this->assertSame($before, $r->refresh()->getAttributes());
            $this->assertSame($audits, Auditoria::query()->get()->toArray());
            $this->assertDatabaseCount('remisiones_eps', 1);
            $this->assertDatabaseCount('cambios_estado_remision', 0);
        }
        $this->assertSame($pacienteBefore, $this->paciente->refresh()->getAttributes());
        $this->assertSame($epsBefore, $this->eps->refresh()->getAttributes());
        $this->get('/dashboard')->assertSee('Registrar remisión')->assertDontSee('Consultar remisiones')->assertDontSee('Listar remisiones');
    }

    public function test_cancellation_requires_reason_confirmation_and_valid_transition_without_deletion(): void
    {
        $r = $this->remision();
        $this->actingAs($this->operador(['RF-023']));
        $this->get(route('remisiones.state', $r))->assertOk();
        foreach ([['estado' => 'ANULADA', 'confirmacion' => '1'], ['estado' => 'ANULADA', 'motivo' => 'Ficticio']] as $data) {
            $this->patch(route('remisiones.changeState', $r), $data)->assertSessionHasErrors();
        }
        $data = ['estado' => 'ANULADA', 'motivo' => 'Ficticio', 'confirmacion' => '1'];
        $this->patch(route('remisiones.changeState', $r), $data)->assertSessionHasNoErrors()->assertRedirect(route('remisiones.state', $r));
        $this->patch(route('remisiones.changeState', $r), $data)->assertInvalid('estado');
        $this->assertDatabaseCount('remisiones_eps', 1);
        $this->assertDatabaseCount('cambios_estado_remision', 1);
        $this->assertDatabaseCount('auditorias', 1);
    }

    public function test_search_disambiguates_eps_and_returns_all_patient_document_matches_without_clinical_history(): void
    {
        $one = $this->remision(['numero_remision' => '001-SHARED']);
        $other = Eps::factory()->create();
        $two = $this->remision(['numero_remision' => '001-SHARED', 'eps_id' => $other->id, 'estado' => 'USADA']);
        $this->paciente->historiaClinica()->create(['fecha_apertura' => now()]);
        $this->actingAs($this->operador(['RF-021']));
        $this->get(route('remisiones.search', ['buscar' => '001-SHARED']))->assertOk()->assertSee($this->eps->nit)->assertSee($other->nit)->assertSee(route('remisiones.show', $two), false);
        $this->get(route('remisiones.search', ['buscar' => $this->paciente->numero_documento]))->assertViewHas('remisiones', fn ($rs) => $rs->count() === 2);
        $this->get(route('remisiones.search', ['buscar' => '001-SHARED', 'eps_id' => $other->id]))->assertViewHas('remisiones', fn ($rs) => $rs->pluck('id')->all() === [$two->id]);
        $this->get(route('remisiones.show', $one))->assertOk()->assertSee($one->numero_remision)->assertDontSee('Antecedentes clínicos');
        $this->get('/remisiones/999999')->assertNotFound();
        $this->get(route('remisiones.index'))->assertForbidden();
    }

    public function test_expiry_is_evaluated_in_bogota_inclusively_without_mutating_reads_or_terminal_states(): void
    {
        $r = $this->remision(['vigencia_hasta' => '2026-10-25']);
        $used = $this->remision(['vigencia_hasta' => '2026-10-25', 'estado' => 'USADA']);
        $cancelled = $this->remision(['vigencia_hasta' => '2026-10-25', 'estado' => 'ANULADA']);
        $before = $r->getAttributes();
        $this->actingAs($this->operador(['RF-021', 'RF-022']));
        $this->travelTo(Carbon::parse('2026-10-26 01:00:00', 'UTC'));
        $this->get(route('remisiones.index', ['estado' => 'VIGENTE']))->assertViewHas('remisiones', fn ($rs) => $rs->pluck('id')->all() === [$r->id]);
        $this->travelTo(Carbon::parse('2026-10-26 05:00:00', 'UTC'));
        $this->get(route('remisiones.index', ['estado' => 'VENCIDA', 'eps_id' => $this->eps->id]))->assertOk()->assertSee('Total de registros: 1')->assertViewHas('remisiones', fn ($rs) => $rs->pluck('id')->all() === [$r->id]);
        $this->get(route('remisiones.show', $r))->assertSee('VENCIDA');
        $this->assertSame('USADA', $used->estadoActual());
        $this->assertSame('ANULADA', $cancelled->estadoActual());
        $this->assertSame($before, $r->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
        $this->get(route('remisiones.index', ['estado' => 'VIGENTE']))->assertSee('Total de registros: 0');
    }

    public function test_filters_are_combined_and_invalid_or_unexpected_query_parameters_rejected(): void
    {
        $r = $this->remision();
        $this->remision(['eps_id' => Eps::factory()->create()->id, 'estado' => 'ANULADA']);
        $this->actingAs($this->operador());
        $this->get(route('remisiones.index', ['eps_id' => $this->eps->id, 'estado' => 'VIGENTE']))->assertViewHas('remisiones', fn ($rs) => $rs->pluck('id')->all() === [$r->id]);
        foreach ([['estado' => 'X'], ['eps_id' => 999999], ['eps_id' => ['id' => [99999]]], ['user_id' => 1]] as $filters) {
            $this->get(route('remisiones.index', $filters))->assertSessionHasErrors();
        }
        $this->get(route('remisiones.search', ['buscar' => ['X']]))->assertInvalid('buscar');
        $this->get(route('remisiones.search', ['eps_id' => ['id' => [99999]]]))->assertInvalid('eps_id');
        $this->get(route('remisiones.search', ['buscar' => 'inexistente']))->assertSee('Total de registros: 0');
    }

    public static function validityRanges(): array
    {
        return [
            [['vigencia_desde' => '2026-10-25', 'vigencia_hasta' => '2026-10-31'], [1, 2]],
            [['vigencia_desde' => '2026-10-25', 'vigencia_hasta' => '2026-10-25'], [1]],
            [['vigencia_desde' => '2026-10-31'], [2, 3]],
            [['vigencia_hasta' => '2026-10-25'], [0, 1]],
            [['vigencia_desde' => '', 'vigencia_hasta' => ''], [0, 1, 2, 3]],
            [['vigencia_desde' => '   ', 'vigencia_hasta' => '   '], [0, 1, 2, 3]],
            [['vigencia_desde' => '2027-01-01', 'vigencia_hasta' => '2027-02-01'], []],
        ];
    }

    #[DataProvider('validityRanges')]
    public function test_validity_range_filters_expiry_date_inclusively_with_optional_bounds(array $filters, array $indexes): void
    {
        $records = [];
        foreach (['2026-10-20', '2026-10-25', '2026-10-31', '2026-11-01'] as $until) {
            $records[] = $this->remision(['fecha_remision' => '2026-09-01', 'vigencia_hasta' => $until]);
        }
        $expected = array_map(fn (int $i): int => $records[$i]->id, $indexes);
        $before = RemisionEps::query()->get()->toArray();
        $this->actingAs($this->operador(['RF-022']))->get(route('remisiones.index', $filters))
            ->assertOk()->assertSee('Total de registros: '.count($expected))
            ->assertSee('Fecha de vencimiento desde (inclusive)')->assertSee('Fecha de vencimiento hasta (inclusive)')
            ->assertViewHas('remisiones', fn ($rs): bool => $rs->pluck('id')->all() === $expected);
        $this->assertSame($before, RemisionEps::query()->get()->toArray());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_validity_range_combines_with_eps_and_effective_state_using_and(): void
    {
        $first = $this->remision(['fecha_remision' => '2026-10-01', 'vigencia_hasta' => '2026-10-20']);
        $this->remision(['fecha_remision' => '2026-10-01', 'vigencia_hasta' => '2026-10-20', 'estado' => 'ANULADA']);
        $this->remision(['fecha_remision' => '2026-10-01', 'vigencia_hasta' => '2026-10-20', 'eps_id' => Eps::factory()->create()->id]);
        $this->remision(['fecha_remision' => '2026-10-01', 'vigencia_hasta' => '2026-10-24']);
        $last = $this->remision(['fecha_remision' => '2026-10-01', 'vigencia_hasta' => '2026-10-22']);
        $this->actingAs($this->operador(['RF-022']))->get(route('remisiones.index', [
            'eps_id' => $this->eps->id, 'estado' => 'VENCIDA', 'vigencia_desde' => '2026-10-20', 'vigencia_hasta' => '2026-10-22',
        ]))->assertOk()->assertSee('Total de registros: 2')
            ->assertViewHas('remisiones', fn ($rs): bool => $rs->pluck('id')->all() === [$first->id, $last->id]);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function invalidValidityBounds(): array
    {
        $cases = [];
        foreach (['vigencia_desde', 'vigencia_hasta'] as $field) {
            foreach (['2026-02-30', '25/10/2026', ['fecha' => ['2026-10-25']], "2026-10\0-25", "2026-10-25' OR 1=1 --"] as $value) {
                $cases[] = [$field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidValidityBounds')]
    public function test_invalid_validity_bounds_return_controlled_validation_without_writes(string $field, mixed $value): void
    {
        $r = $this->remision();
        $before = $r->getAttributes();
        $filters = ['vigencia_desde' => '2026-10-01', 'vigencia_hasta' => '2026-10-31', $field => $value];
        $this->actingAs($this->operador(['RF-022']))->getJson(route('remisiones.index', $filters))->assertUnprocessable()->assertInvalid($field);
        $this->assertSame($before, $r->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_inverted_validity_range_is_rejected_and_range_is_not_an_rf021_filter(): void
    {
        $this->actingAs($this->operador());
        $this->get(route('remisiones.index', ['vigencia_desde' => '2026-11-01', 'vigencia_hasta' => '2026-10-31']))->assertInvalid('vigencia_hasta');
        $this->get(route('remisiones.search', ['vigencia_desde' => '2026-10-01']))->assertInvalid('vigencia_desde');
        $this->assertDatabaseCount('auditorias', 0);
    }

    private function operations(RemisionEps $r): array
    {
        return [['GET', route('remisiones.index'), []], ['GET', route('remisiones.search'), []], ['GET', route('remisiones.create'), []], ['GET', route('remisiones.show', $r), []], ['GET', route('remisiones.edit', $r), []], ['GET', route('remisiones.state', $r), []], ['POST', route('remisiones.store'), $this->data()], ['PUT', route('remisiones.update', $r), $this->editData($r)], ['PATCH', route('remisiones.changeState', $r), ['estado' => 'ANULADA', 'motivo' => 'Ficticio', 'confirmacion' => '1']]];
    }

    public function test_permission_is_required_for_all_routes_even_with_administrator_cargo(): void
    {
        $r = $this->remision();
        $user = $this->operador([]);
        $user->update(['cargo' => 'Administrador']);
        $this->actingAs($user);
        foreach ($this->operations($r) as [$method,$url,$data]) {
            $this->call($method, $url, $data)->assertForbidden()->assertDontSee($r->servicio_autorizado);
        }
        $this->get('/dashboard')->assertDontSee('Registrar remisión')->assertDontSee('Listar remisiones');
        $this->assertSame('VIGENTE', $r->refresh()->estado);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function guestOrInactive(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('guestOrInactive')]
    public function test_guests_and_inactive_accounts_cannot_use_any_route(bool $inactive): void
    {
        $r = $this->remision();
        $user = $this->operador();
        $user->update(['estado' => 'inactivo']);
        foreach ($this->operations($r) as [$method,$url,$data]) {
            if ($inactive) {
                $this->actingAs($user);
            }
            $this->call($method, $url, $data)->assertRedirect('/login');
            $this->assertGuest();
        }
        $this->assertSame('VIGENTE', $r->refresh()->estado);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function writes(): array
    {
        return [['store'], ['update'], ['cancel'], ['revive']];
    }

    #[DataProvider('writes')]
    public function test_audit_failure_rolls_back_remission_state_history_and_all_writes(string $action): void
    {
        $r = $this->remision(['fecha_remision' => '2026-10-01', 'vigencia_hasta' => '2026-10-24', 'estado' => 'VENCIDA']);
        $before = $r->getAttributes();
        $this->actingAs($this->operador())->withoutExceptionHandling();
        Auditoria::created(static function (): never {
            throw new RuntimeException('Fallo ficticio de auditoría');
        });
        try {
            if ($action === 'store') {
                $this->post(route('remisiones.store'), $this->data());
            } elseif ($action === 'cancel') {
                $this->patch(route('remisiones.changeState', $r), ['estado' => 'ANULADA', 'motivo' => 'Ficticio', 'confirmacion' => '1']);
            } else {
                $this->put(route('remisiones.update', $r), $this->editData($r, ['vigencia_hasta' => $action === 'revive' ? '2026-11-25' : '2026-10-24', 'motivo' => 'Ficticio']));
            }
            $this->fail('Se esperaba el fallo.');
        } catch (RuntimeException $e) {
            $this->assertSame('Fallo ficticio de auditoría', $e->getMessage());
            $this->assertSame($before, $r->refresh()->getAttributes());
            $this->assertDatabaseCount('remisiones_eps', 1);
            $this->assertDatabaseCount('cambios_estado_remision', 0);
            $this->assertDatabaseCount('auditorias', 0);
        } finally {
            Auditoria::flushEventListeners();
        }
    }

    public function test_foreign_keys_prevent_missing_parents_and_deletion_of_historical_eps(): void
    {
        $this->remision();
        try {
            $this->eps->delete();
            $this->fail('La FK debe proteger la EPS.');
        } catch (QueryException) {
            $this->assertDatabaseHas('eps', ['id' => $this->eps->id]);
        }
        try {
            $this->remision(['paciente_id' => 99999]);
            $this->fail('La FK debe proteger el paciente.');
        } catch (QueryException) {
            $this->assertDatabaseCount('remisiones_eps', 1);
        }
    }

    public function test_eps_inactivation_and_contact_edit_preserve_historical_remissions_and_block_new_registration(): void
    {
        $r = $this->remision();
        $before = $r->getAttributes();
        $actor = $this->operador(['RF-019', 'RF-021', 'RF-016']);
        $data = $this->eps->only(['nit', 'nombre', 'telefono', 'correo_electronico', 'direccion', 'ciudad', 'estado']);
        $this->actingAs($actor)->put(route('eps.update', $this->eps), [...$data, 'nombre' => 'Nombre corregido', 'estado' => 'INACTIVA', 'motivo' => 'Motivo ficticio'])->assertSessionHasNoErrors();
        $this->assertSame($before, $r->refresh()->getAttributes());
        $this->get(route('remisiones.show', $r))->assertOk()->assertSee('Nombre corregido');
        $this->post(route('remisiones.store'), $this->data(['numero_remision' => 'OTRA']))->assertInvalid('eps_id');
    }

    public function test_permissions_seeder_is_idempotent_preserves_other_assignments_and_requires_no_medical_profile(): void
    {
        $rol = Rol::query()->create(['nombre' => 'Administrador']);
        $existing = Permiso::query()->create(['codigo' => 'RF-015', 'descripcion' => 'Ficticio']);
        $rol->permisos()->attach($existing);
        $this->seed(RemisionPermissionsSeeder::class);
        $this->seed(RemisionPermissionsSeeder::class);
        foreach (['Administrador', 'Médico', 'Administrativo'] as $name) {
            $role = Rol::query()->where('nombre', $name)->firstOrFail();
            $this->assertSame(5, $role->permisos()->whereIn('codigo', ['RF-019', 'RF-020', 'RF-021', 'RF-022', 'RF-023'])->count());
            $user = User::factory()->create(['rol_id' => $role->id]);
            $this->assertNull($user->medico);
            $this->actingAs($user)->get(route('remisiones.index'))->assertOk();
            $this->post(route('remisiones.store'), $this->data(['numero_remision' => 'DEMO-'.$user->id]))->assertSessionHasNoErrors();
        }
        $this->assertTrue($rol->permisos()->where('codigo', 'RF-015')->exists());
        $this->assertFalse(Permiso::query()->whereIn('codigo', ['RF-025', 'RF-031', 'RF-058'])->exists());
    }

    public function test_mysql_ddl_compiles_foreign_keys_and_composite_unique_without_connecting(): void
    {
        $connection = new class(static function (): never {
            throw new LogicException('No conectar a MySQL.');
        }, 'preview', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']) extends MySqlConnection {

            public function getServerVersion(): string
            {
                return '8.0.36';
            }

            public function isMaria(): bool
            {
                return false;
            } };
        $original = Schema::getFacadeRoot();
        Schema::swap($connection->getSchemaBuilder());
        try {
            $queries = $connection->pretend(static function (): void {
                (require database_path('migrations/2026_10_25_224612_create_remisiones_eps_and_estado_cambios_tables.php'))->up();
            });
            $sql = implode("\n", array_column($queries, 'query'));
            $this->assertStringContainsString('engine = InnoDB', $sql);
            $this->assertStringContainsString('add unique', $sql);
            $this->assertStringContainsString('(`eps_id`, `numero_remision`)', $sql);
            $this->assertStringContainsString('references `pacientes` (`id`) on delete restrict', $sql);
            $this->assertStringContainsString('references `eps` (`id`) on delete restrict', $sql);
            $this->assertStringNotContainsString('drop ', $sql);
        } finally {
            Schema::swap($original);
        }
    }
}
