<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Eps;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\EpsPermissionsSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Illuminate\View\ViewException;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * RF-015 a RF-018: CA-015-01/04, CA-016-01/04, CA-017-01/04 y CA-018-01/04.
 * CA-016-02/03, citas futuras y nuevas remisiones de CA-018-03 se integrarán después.
 */
class EpsCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const SQL_BINDING = 'EPS_BINDING_FICTICIO_I1';

    private const SQL_ORIGINAL = 'EPS_ERROR_ORIGINAL_FICTICIO_I1';

    private const SQL_WRAPPER = 'EPS_ENVUELTA_FICTICIA_I1';

    private const SQL_RESPONSE = 'No fue posible completar la operación de EPS. Intente nuevamente.';

    private ?string $logPath = null;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing') || config('database.default') !== 'sqlite') {
            throw new LogicException('Pruebas canceladas: se requiere SQLite en memoria.');
        }
        foreach ($this->connectionsToTransact() as $name) {
            $config = $this->app['db']->connection($name)->getConfig();
            if ($config['driver'] !== 'sqlite' || $config['database'] !== ':memory:' || ! empty($config['url'])) {
                throw new LogicException('Pruebas canceladas: conexión no aislada.');
            }
        }
    }

    protected function tearDown(): void
    {
        try {
            Log::forgetChannel('eps_security_test');
            if ($this->logPath !== null && is_file($this->logPath)) {
                unlink($this->logPath);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function operador(array $codigos = ['RF-015', 'RF-016', 'RF-017', 'RF-018']): User
    {
        $this->seed(EpsPermissionsSeeder::class);
        $rol = Rol::query()->create(['nombre' => 'EPS prueba '.uniqid()]);
        $rol->permisos()->sync(Permiso::query()->whereIn('codigo', $codigos)->pluck('id'));

        return User::factory()->create(['rol_id' => $rol->id])->refresh();
    }

    /** @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return ['nit' => '00DEMO.123-XY', 'nombre' => 'EPS ficticia del Valle', 'telefono' => '003001234567',
            'correo_electronico' => 'contacto@example.com', 'direccion' => 'Dirección ficticia',
            'ciudad' => 'Cali', 'estado' => 'ACTIVA', ...$cambios];
    }

    /** @return array<string, mixed> */
    private function editar(Eps $eps, array $cambios = []): array
    {
        return [...$eps->only(['nit', 'nombre', 'telefono', 'correo_electronico', 'direccion', 'ciudad', 'estado']), ...$cambios];
    }

    public static function estadosPermitidos(): array
    {
        return [['ACTIVA'], ['INACTIVA']];
    }

    #[DataProvider('estadosPermitidos')]
    public function test_create_stores_normalized_nit_contact_city_and_state_without_user_or_external_entities(string $estado): void
    {
        $actor = $this->operador();
        $antes = $actor->getAttributes();
        $this->actingAs($actor)->get(route('eps.create'))->assertOk()->assertSee('¿Confirmar el registro de la EPS?');
        $this->post(route('eps.store'), $this->datos(['nit' => '  00demo.123-xy  ', 'estado' => $estado]))->assertSessionHasNoErrors();
        $eps = Eps::query()->sole();
        $this->assertSame('00DEMO.123-XY', $eps->nit);
        $this->assertSame('003001234567', $eps->telefono);
        $this->assertSame('Cali', $eps->ciudad);
        $this->assertSame($estado, $eps->estado);
        $this->assertSame($antes, $actor->refresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse(Schema::hasColumn('eps', 'user_id'));
        $this->assertDatabaseCount('remisiones_eps', 0);
        $audit = Auditoria::query()->sole();
        $this->assertSame('EPS_CREADA', $audit->accion);
        $this->assertSame('EPS', $audit->entidad);
        $this->assertSame($eps->id, $audit->registro_id);
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame('EXITO', $audit->resultado);
        $this->assertNotNull($audit->fecha_hora);
        $this->assertStringNotContainsString($eps->nit, $audit->descripcion);
        $this->assertStringNotContainsString($eps->correo_electronico, $audit->descripcion);
    }

    public function test_nit_normalization_keeps_punctuation_and_leading_zeros_and_database_enforces_uniqueness(): void
    {
        $eps = Eps::factory()->create(['nit' => ' 00ab-1 ']);
        $this->assertSame('00AB-1', $eps->nit);
        Eps::factory()->create(['nit' => '00AB1']);
        try {
            Eps::factory()->create(['nit' => '00ab-1']);
            $this->fail('La restricción de BD debe impedir el duplicado.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('eps', 2);
        }
        $this->actingAs($this->operador())->post(route('eps.store'), $this->datos(['nit' => ' 00ab-1 ']))->assertInvalid('nit');
        $this->assertDatabaseCount('eps', 2);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_edit_keeps_identity_created_date_and_other_domains_and_excludes_own_nit(): void
    {
        $actor = $this->operador();
        $eps = Eps::factory()->create(['created_at' => now()->subYear()])->refresh();
        $id = $eps->id;
        $creada = $eps->getRawOriginal('created_at');
        $otra = Eps::factory()->create()->refresh();
        $otraAntes = $otra->getAttributes();
        $paciente = Paciente::factory()->create()->refresh();
        $historia = $paciente->historiaClinica()->create(['fecha_apertura' => now()])->refresh();
        $pacienteAntes = $paciente->getAttributes();
        $historiaAntes = $historia->getAttributes();
        $this->actingAs($actor)->get(route('eps.edit', $eps))->assertOk()->assertSee($eps->nit);
        $this->put(route('eps.update', $eps), $this->editar($eps, ['nombre' => 'Nombre corregido', 'ciudad' => 'Yumbo']))->assertSessionHasNoErrors();
        $eps->refresh();
        $this->assertSame($id, $eps->id);
        $this->assertSame($creada, $eps->getRawOriginal('created_at'));
        $this->assertSame('Yumbo', $eps->ciudad);
        $this->assertSame($otraAntes, $otra->refresh()->getAttributes());
        $this->assertSame($pacienteAntes, $paciente->refresh()->getAttributes());
        $this->assertSame($historiaAntes, $historia->refresh()->getAttributes());
        $this->get(route('eps.show', $eps))->assertSee('Nombre corregido')->assertSee('Yumbo');
        $this->assertSame('EPS_ACTUALIZADA', Auditoria::query()->sole()->accion);
    }

    public function test_nit_can_be_corrected_but_cannot_use_another_eps_nit_or_forged_ignore_id(): void
    {
        $eps = Eps::factory()->create();
        $otra = Eps::factory()->create();
        $this->actingAs($this->operador())->put(route('eps.update', $eps), $this->editar($eps, ['nit' => $otra->nit, 'id' => $otra->id]))->assertInvalid(['nit', 'id']);
        $this->put(route('eps.update', $eps), $this->editar($eps, ['nit' => '00NUEVO-DEMO']))->assertSessionHasNoErrors();
        $this->assertSame('00NUEVO-DEMO', $eps->refresh()->nit);
    }

    public function test_state_changes_require_reason_in_both_directions_and_preserve_each_transition_in_audit(): void
    {
        $eps = Eps::factory()->create();
        $this->actingAs($this->operador());
        $this->put(route('eps.update', $eps), $this->editar($eps, ['estado' => 'INACTIVA']))->assertInvalid('motivo');
        $this->assertSame('ACTIVA', $eps->refresh()->estado);
        foreach ([['INACTIVA', 'Baja administrativa ficticia'], ['ACTIVA', 'Reactivación administrativa ficticia']] as [$estado, $motivo]) {
            $this->patch(route('eps.update', $eps), $this->editar($eps, ['estado' => $estado, 'motivo' => $motivo]))->assertSessionHasNoErrors();
            $this->assertSame($estado, $eps->refresh()->estado);
        }
        $audits = Auditoria::query()->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertStringContainsString('ACTIVA -> INACTIVA', $audits[0]->descripcion);
        $this->assertStringContainsString('Baja administrativa ficticia', $audits[0]->descripcion);
        $this->assertStringContainsString('INACTIVA -> ACTIVA', $audits[1]->descripcion);
        $this->assertStringContainsString('Reactivación administrativa ficticia', $audits[1]->descripcion);
        $this->assertFalse(Schema::hasColumn('eps', 'motivo'));
    }

    public function test_reason_is_revalidated_when_state_changes_between_validation_and_transaction(): void
    {
        $eps = Eps::factory()->create(['estado' => 'ACTIVA']);
        $this->actingAs($this->operador());
        $inyectado = false;
        DB::listen(function (QueryExecuted $query) use ($eps, &$inyectado): void {
            if (! $inyectado && str_contains($query->sql, 'count(') && str_contains($query->sql, '"eps"')) {
                $inyectado = true;
                Eps::query()->whereKey($eps->id)->update(['estado' => 'INACTIVA']);
            }
        });
        $this->put(route('eps.update', $eps), $this->editar($eps))->assertInvalid('motivo');
        $this->assertTrue($inyectado);
        $this->assertSame('INACTIVA', $eps->refresh()->estado);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_maximum_reason_and_zero_text_persist_in_sqlite_and_fit_audit_character_limit(): void
    {
        $eps = Eps::factory()->create();
        $this->actingAs($this->operador())->put(route('eps.update', $eps), $this->editar($eps, ['estado' => 'INACTIVA', 'motivo' => str_repeat('á', 400)]))->assertSessionHasNoErrors();
        $this->assertLessThanOrEqual(500, mb_strlen(Auditoria::query()->sole()->descripcion));
        $eps->refresh();
        $this->put(route('eps.update', $eps), $this->editar($eps, ['estado' => 'ACTIVA', 'motivo' => '0']))->assertSessionHasNoErrors();
    }

    public function test_search_by_nit_or_name_selects_correct_record_and_inactive_detail_is_readable(): void
    {
        $uno = Eps::factory()->create(['nit' => '001-DEMO-A', 'nombre' => 'Entidad Ficticia Valle', 'estado' => 'INACTIVA']);
        $dos = Eps::factory()->create(['nit' => '002-DEMO-B', 'nombre' => 'Entidad Ficticia Valle Norte']);
        $this->actingAs($this->operador(['RF-017']));
        $this->get(route('eps.search', ['buscar' => '001-demo']))->assertOk()->assertSee('001-DEMO-A')->assertDontSee('002-DEMO-B');
        $this->get(route('eps.search', ['buscar' => 'valle']))->assertOk()->assertSee($uno->nit)->assertSee($dos->nit)->assertSee(route('eps.show', $dos), false);
        $this->get(route('eps.show', $uno))->assertOk()->assertSee('INACTIVA')->assertDontSee('Editar EPS');
        $this->get(route('eps.show', $dos))->assertSee('002-DEMO-B')->assertDontSee('001-DEMO-A');
        $this->get(route('eps.search', ['buscar' => 'sin coincidencias']))->assertSee('Total de registros: 0');
        $this->get('/eps/999999')->assertNotFound();
        $this->get(route('eps.index'))->assertForbidden();
    }

    public function test_list_filters_city_and_state_together_and_returns_total_zero(): void
    {
        $uno = Eps::factory()->create(['ciudad' => 'Cali', 'estado' => 'ACTIVA']);
        $dos = Eps::factory()->create(['ciudad' => 'Cali', 'estado' => 'INACTIVA']);
        $tres = Eps::factory()->create(['ciudad' => 'Yumbo', 'estado' => 'INACTIVA']);
        $this->actingAs($this->operador(['RF-018']));
        $this->get(route('eps.index'))->assertOk()->assertSee('Total de registros: 3')->assertSee($uno->nit)->assertSee($dos->nit)->assertSee($tres->nit)->assertDontSee('Ver ficha');
        $this->get(route('eps.index', ['ciudad' => 'Cali', 'estado' => 'INACTIVA']))->assertOk()->assertSee('Total de registros: 1')->assertSee($dos->nit)->assertDontSee($uno->nit)->assertDontSee($tres->nit);
        $this->get(route('eps.index', ['estado' => 'INACTIVA']))->assertSee('Total de registros: 2')->assertSee($dos->nit)->assertSee($tres->nit);
        $this->get(route('eps.index', ['ciudad' => 'Sin resultados']))->assertSee('Total de registros: 0');
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function partialCities(): array
    {
        return [['yumb', 'Yumbo'], ['YUM', 'Yumbo'], ['cal', 'Cali'], ['Cali', 'Cali'], ['umB', 'Yumbo'], ['  yUM  ', 'Yumbo']];
    }

    #[DataProvider('partialCities')]
    public function test_city_filter_matches_partial_text_without_case_sensitivity(string $term, string $city): void
    {
        $expected = Eps::factory()->create(['ciudad' => $city]);
        $other = Eps::factory()->create(['ciudad' => 'Palmira']);
        $this->actingAs($this->operador(['RF-018']))
            ->get(route('eps.index', ['ciudad' => $term]))
            ->assertOk()->assertSee('Total de registros: 1')->assertSee($expected->nit)->assertDontSee($other->nit)
            ->assertViewHas('entidades', fn ($entities): bool => $entities->pluck('id')->all() === [$expected->id]);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function literalCityCharacters(): array
    {
        return [
            ['%', 'Villa%Norte', 'Villa Norte'],
            ['_', 'San_Jose', 'SanXJose'],
            ['!', 'Villa!Norte', 'Villa Norte'],
            ['\\', 'Villa\\Sur', 'Villa Sur'],
            ["O'H", "O'Higgins", 'OHiggins'],
            ['!%_', 'Especial!%_Centro', 'Especial!XXCentro'],
            ['%_', 'Villa%_Norte', 'VillaXXNorte'],
            ['!!', 'Zona!!Centro', 'Zona!Centro'],
        ];
    }

    #[DataProvider('literalCityCharacters')]
    public function test_city_filter_treats_wildcards_escape_character_and_quotes_as_literal_text(string $term, string $city, string $otherCity): void
    {
        $expected = Eps::factory()->create(['ciudad' => $city]);
        $other = Eps::factory()->create(['ciudad' => $otherCity]);
        $this->actingAs($this->operador(['RF-018']))
            ->get(route('eps.index', ['ciudad' => $term]))
            ->assertOk()->assertSee('Total de registros: 1')->assertSee($expected->nit)->assertDontSee($other->nit)
            ->assertViewHas('entidades', fn ($entities): bool => $entities->pluck('id')->all() === [$expected->id]);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_partial_city_filter_keeps_and_state_filter_empty_behavior_totals_and_id_order(): void
    {
        $first = Eps::factory()->create(['ciudad' => 'Yumbo', 'estado' => 'INACTIVA']);
        $otherCity = Eps::factory()->create(['ciudad' => 'Cali', 'estado' => 'INACTIVA']);
        $otherState = Eps::factory()->create(['ciudad' => 'YUMBO', 'estado' => 'ACTIVA']);
        $last = Eps::factory()->create(['ciudad' => 'Yumbo, Valle del Cauca', 'estado' => 'INACTIVA']);
        $this->actingAs($this->operador(['RF-018']));
        $this->get(route('eps.index', ['ciudad' => 'yUM', 'estado' => 'INACTIVA']))
            ->assertOk()->assertSee('Total de registros: 2')->assertDontSee($otherCity->nit)->assertDontSee($otherState->nit)
            ->assertViewHas('entidades', fn ($entities): bool => $entities->pluck('id')->all() === [$first->id, $last->id]);
        foreach (['', '   '] as $empty) {
            $this->get(route('eps.index', ['ciudad' => $empty, 'estado' => 'INACTIVA']))
                ->assertOk()->assertSee('Total de registros: 3')
                ->assertViewHas('entidades', fn ($entities): bool => $entities->pluck('id')->all() === [$first->id, $otherCity->id, $last->id]);
            $this->get(route('eps.index', ['ciudad' => $empty]))
                ->assertOk()->assertSee('Total de registros: 4')
                ->assertViewHas('entidades', fn ($entities): bool => $entities->pluck('id')->all() === [$first->id, $otherCity->id, $otherState->id, $last->id]);
        }
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_city_filter_with_no_matches_or_sql_like_input_returns_empty_results(): void
    {
        $eps = Eps::factory()->create(['ciudad' => 'Cali', 'estado' => 'ACTIVA']);
        $this->actingAs($this->operador(['RF-018']));
        foreach (['inexistente', "' OR 1=1 --", '%', '_', '!%'] as $term) {
            $this->get(route('eps.index', ['ciudad' => $term, 'estado' => 'ACTIVA']))
                ->assertOk()->assertSee('Total de registros: 0')->assertDontSee($eps->nit)
                ->assertViewHas('entidades', fn ($entities): bool => $entities->isEmpty());
        }
        $this->assertDatabaseCount('eps', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_only_create_or_edit_permission_does_not_require_detail_permission_or_grant_other_operations(): void
    {
        $eps = Eps::factory()->create();
        $this->actingAs($this->operador(['RF-015']))->post(route('eps.store'), $this->datos())->assertSessionHasNoErrors()->assertRedirect(route('eps.create'));
        $this->get(route('eps.show', $eps))->assertForbidden();
        $this->get(route('eps.index'))->assertForbidden();
        $this->get(route('eps.edit', $eps))->assertForbidden();
        $this->actingAs($this->operador(['RF-016']))->put(route('eps.update', $eps), $this->editar($eps, ['ciudad' => 'Palmira']))->assertSessionHasNoErrors()->assertRedirect(route('eps.edit', $eps));
        $this->get(route('eps.edit', $eps))->assertOk();
        $this->get(route('eps.show', $eps))->assertForbidden();
        $this->get(route('eps.create'))->assertForbidden();
    }

    public function test_users_without_rf_permissions_are_forbidden_even_with_administrator_cargo(): void
    {
        $eps = Eps::factory()->create();
        $user = $this->operador([]);
        $user->update(['cargo' => 'Administrador']);
        $this->actingAs($user);
        foreach (['/eps', '/eps/buscar', '/eps/create', '/eps/'.$eps->id, '/eps/'.$eps->id.'/edit'] as $url) {
            $this->get($url)->assertForbidden()->assertDontSee($eps->nit);
        }
        $this->post(route('eps.store'), $this->datos())->assertForbidden();
        $this->put(route('eps.update', $eps), $this->editar($eps))->assertForbidden();
        $this->get('/dashboard')->assertDontSee('Registrar EPS')->assertDontSee('Listar EPS')->assertDontSee('Consultar EPS');
        $this->assertDatabaseCount('eps', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_guests_and_inactive_accounts_cannot_execute_eps_operations(): void
    {
        $eps = Eps::factory()->create();
        $user = $this->operador();
        $user->update(['estado' => 'inactivo']);
        foreach ([null, $user] as $actor) {
            foreach (['/eps', '/eps/buscar', '/eps/create', '/eps/'.$eps->id, '/eps/'.$eps->id.'/edit'] as $url) {
                if ($actor) {
                    $this->actingAs($actor);
                }
                $this->get($url)->assertRedirect('/login');
                $this->assertGuest();
            }
            foreach (['POST', 'PUT'] as $method) {
                if ($actor) {
                    $this->actingAs($actor);
                }
                $this->call($method, $method === 'POST' ? route('eps.store') : route('eps.update', $eps), $this->datos())->assertRedirect('/login');
            }
        }
        $this->assertDatabaseCount('eps', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function obligatorios(): array
    {
        return array_map(fn (string $campo): array => [$campo], ['nit', 'nombre', 'telefono', 'correo_electronico', 'direccion', 'ciudad', 'estado']);
    }

    #[DataProvider('obligatorios')]
    public function test_required_fields_are_validated_in_create_and_update(string $campo): void
    {
        $eps = Eps::factory()->create()->refresh();
        $antes = $eps->getAttributes();
        $this->actingAs($this->operador());
        $alta = $this->datos();
        unset($alta[$campo]);
        $this->post(route('eps.store'), $alta)->assertInvalid($campo);
        $cambio = $this->editar($eps);
        unset($cambio[$campo]);
        $this->put(route('eps.update', $eps), $cambio)->assertInvalid($campo);
        $this->assertSame($antes, $eps->refresh()->getAttributes());
        $this->assertDatabaseCount('eps', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function invalidos(): array
    {
        return [['nit', ['DEMO']], ['nit', str_repeat('a', 51)], ['nombre', ' '], ['telefono', 123], ['correo_electronico', 'invalido'], ['ciudad', str_repeat('a', 101)], ['estado', 'ACTIVO'], ['estado', ['ACTIVA']]];
    }

    #[DataProvider('invalidos')]
    public function test_invalid_values_do_not_write_eps_or_audits(string $campo, mixed $valor): void
    {
        $eps = Eps::factory()->create()->refresh();
        $antes = $eps->getAttributes();
        $this->actingAs($this->operador())->post(route('eps.store'), $this->datos([$campo => $valor]))->assertInvalid($campo);
        $this->put(route('eps.update', $eps), $this->editar($eps, [$campo => $valor]))->assertInvalid($campo);
        $this->assertSame($antes, $eps->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function extras(): array
    {
        return [['id', 999], ['user_id', 999], ['eps_id', 999], ['password', 'Ficticio'], ['created_at', '2000-01-01'], ['updated_at', '2000-01-01'], ['remisiones', []], ['historia_clinica_id', null], ['permisos', []]];
    }

    #[DataProvider('extras')]
    public function test_extra_fields_are_rejected_in_both_writes(string $campo, mixed $valor): void
    {
        $eps = Eps::factory()->create()->refresh();
        $antes = $eps->getAttributes();
        $this->actingAs($this->operador())->post(route('eps.store'), $this->datos([$campo => $valor]))->assertInvalid($campo);
        $this->put(route('eps.update', $eps), $this->editar($eps, [$campo => $valor]))->assertInvalid($campo);
        $this->assertSame($antes, $eps->refresh()->getAttributes());
        $this->assertDatabaseCount('eps', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_invalid_filters_and_unsupported_parameters_are_rejected(): void
    {
        $this->actingAs($this->operador())->get(route('eps.index', ['estado' => 'ACTIVO']))->assertInvalid('estado');
        $this->get(route('eps.index', ['ciudad' => ['Cali']]))->assertInvalid('ciudad');
        $this->get(route('eps.index', ['rol_id' => 1]))->assertInvalid('rol_id');
        $this->get(route('eps.search', ['buscar' => ['texto']]))->assertInvalid('buscar');
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_nit_collision_after_validation_is_a_controlled_error(): void
    {
        $this->actingAs($this->operador());
        $inyectado = false;
        DB::listen(function (QueryExecuted $query) use (&$inyectado): void {
            if (! $inyectado && str_contains($query->sql, 'count(') && str_contains($query->sql, '"eps"')) {
                $inyectado = true;
                Eps::factory()->create(['nit' => '00DEMO.123-XY']);
            }
        });
        $this->post(route('eps.store'), $this->datos())->assertInvalid('nit');
        $this->assertTrue($inyectado);
        $this->assertDatabaseCount('eps', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function escrituras(): array
    {
        return [['store'], ['update']];
    }

    #[DataProvider('escrituras')]
    public function test_audit_failure_rolls_back_catalog_fields_and_state(string $operacion): void
    {
        $eps = Eps::factory()->create()->refresh();
        $antes = $eps->getAttributes();
        $this->actingAs($this->operador())->withoutExceptionHandling();
        Auditoria::created(static function (): void {
            throw new RuntimeException('Fallo ficticio de auditoría EPS');
        });
        try {
            $datos = $this->datos(['estado' => 'INACTIVA', ...($operacion === 'update' ? ['motivo' => 'Cambio ficticio'] : [])]);
            $this->call($operacion === 'store' ? 'POST' : 'PUT', $operacion === 'store' ? route('eps.store') : route('eps.update', $eps), $datos);
            $this->fail('Se esperaba error de auditoría.');
        } catch (RuntimeException $e) {
            $this->assertSame('Fallo ficticio de auditoría EPS', $e->getMessage());
            $this->assertSame($antes, $eps->refresh()->getAttributes());
            $this->assertDatabaseCount('eps', 1);
            $this->assertDatabaseCount('auditorias', 0);
        } finally {
            Auditoria::flushEventListeners();
        }
    }

    private function errorScenario(bool $debug): Eps
    {
        $this->actingAs($this->operador());
        $path = tempnam(sys_get_temp_dir(), 'medisoft-eps-log-');
        if ($path === false) {
            throw new RuntimeException('No se pudo preparar el log aislado de EPS.');
        }
        $this->logPath = $path;
        config([
            'app.debug' => $debug, 'logging.default' => 'eps_security_test',
            'logging.channels.eps_security_test' => ['driver' => 'single', 'path' => $path, 'level' => 'debug', 'replace_placeholders' => true],
        ]);
        Log::forgetChannel('eps_security_test');
        $eps = Eps::factory()->create()->refresh();
        Auditoria::query()->create([
            'user_id' => $this->app['auth']->id(), 'accion' => 'EPS_CREADA', 'entidad' => 'EPS',
            'registro_id' => $eps->id, 'fecha_hora' => now(), 'resultado' => 'EXITO',
            'descripcion' => 'Evento previo ficticio que debe conservarse.',
        ]);

        return $eps;
    }

    private static function sqlFailure(string $sql = 'insert into eps (nombre) values (?)'): QueryException
    {
        return new QueryException('sqlite', $sql, [self::SQL_BINDING], new PDOException('SQLSTATE[HY000]: '.self::SQL_ORIGINAL));
    }

    private function logContent(): string
    {
        $contenido = file_get_contents($this->logPath);
        $this->assertNotFalse($contenido);

        return $contenido;
    }

    private function assertSafeSqlFailure(TestResponse $response, string $route, bool $json): void
    {
        $response->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private')
            ->assertSessionMissing('status')->assertSessionMissing('errors')->assertSessionMissing('_old_input');
        if ($json) {
            $response->assertExactJson(['message' => self::SQL_RESPONSE]);
        } else {
            $response->assertContent(self::SQL_RESPONSE);
        }
        $log = $this->logContent();
        $surfaces = [
            $response->getContent(), $log,
            json_encode($this->app['session']->all(), JSON_THROW_ON_ERROR),
            json_encode(Auditoria::query()->get()->toArray(), JSON_THROW_ON_ERROR),
        ];
        foreach ([self::SQL_BINDING, self::SQL_ORIGINAL, self::SQL_WRAPPER, 'SQLSTATE', 'insert into eps', 'select * from eps', 'PDOException', 'QueryException', 'ViewException', 'Stack trace'] as $sensitive) {
            foreach ($surfaces as $surface) {
                $this->assertStringNotContainsString($sensitive, $surface);
            }
        }
        $this->assertSame(1, substr_count($log, 'Error de base de datos en catálogo EPS.'));
        $this->assertStringContainsString('"modulo":"EPS"', $log);
        $this->assertStringContainsString('"operacion":"'.$route.'"', $log);
        $this->assertStringNotContainsString('"exception"', $log);
        $this->assertStringNotContainsString('"trace"', $log);
        $this->assertStringNotContainsString('Error de base de datos en RF-058.', $log);
    }

    /** @return array<string, array{string, bool, bool}> */
    private static function errorCases(array $stages): array
    {
        $cases = [];
        foreach ($stages as $stage) {
            foreach ([false, true] as $json) {
                foreach ([false, true] as $debug) {
                    $cases[$stage.'-'.($json ? 'json' : 'html').'-debug-'.(int) $debug] = [$stage, $json, $debug];
                }
            }
        }

        return $cases;
    }

    public static function sqlWrites(): array
    {
        return self::errorCases(['registro', 'actualizacion', 'auditoria_registro', 'auditoria_actualizacion']);
    }

    #[DataProvider('sqlWrites')]
    public function test_sql_write_errors_use_real_http_and_logging_without_leaks_and_roll_back(string $stage, bool $json, bool $debug): void
    {
        $eps = $this->errorScenario($debug);
        $before = $eps->getAttributes();
        $audits = Auditoria::query()->get()->toArray();
        $failure = static function (): never {
            throw self::sqlFailure();
        };
        match ($stage) {
            'registro' => Eps::created($failure),
            'actualizacion' => Eps::updated($failure),
            default => Auditoria::created($failure),
        };
        $creating = in_array($stage, ['registro', 'auditoria_registro'], true);
        $route = $creating ? 'eps.store' : 'eps.update';
        $method = $creating ? 'POST' : 'PUT';
        $url = route($route, $creating ? [] : ['eps' => $eps]);
        $data = $creating ? $this->datos() : $this->editar($eps, ['ciudad' => 'Yumbo', 'estado' => 'INACTIVA', 'motivo' => 'Cambio administrativo ficticio']);
        try {
            $response = $json ? $this->json($method, $url, $data) : $this->call($method, $url, $data);
            $this->assertSafeSqlFailure($response, $route, $json);
            $this->assertSame($before, $eps->refresh()->getAttributes());
            $this->assertDatabaseCount('eps', 1);
            $this->assertSame($audits, Auditoria::query()->get()->toArray());
        } finally {
            Eps::flushEventListeners();
            Auditoria::flushEventListeners();
        }
    }

    public static function sqlReads(): array
    {
        return self::errorCases(['index', 'search', 'show', 'edit']);
    }

    #[DataProvider('sqlReads')]
    public function test_sql_read_and_binding_errors_use_real_http_and_logging_without_leaks(string $action, bool $json, bool $debug): void
    {
        $eps = $this->errorScenario($debug);
        $before = $eps->getAttributes();
        $audits = Auditoria::query()->get()->toArray();
        $injected = false;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$injected): void {
            if (! $injected && str_contains($sql, 'select') && str_contains($sql, '"eps"')) {
                $injected = true;
                throw self::sqlFailure('select * from eps where nombre = ?');
            }
        });
        $route = 'eps.'.$action;
        $url = route($route, ['search' => ['buscar' => 'Ficticia'], 'show' => ['eps' => $eps], 'edit' => ['eps' => $eps]][$action] ?? []);
        $response = $json ? $this->getJson($url) : $this->get($url);
        $this->assertTrue($injected);
        $this->assertSafeSqlFailure($response, $route, $json);
        $this->assertSame($before, $eps->refresh()->getAttributes());
        $this->assertSame($audits, Auditoria::query()->get()->toArray());
    }

    public static function sqlViews(): array
    {
        $cases = [];
        foreach (self::errorCases(['create', 'index', 'search', 'show', 'edit']) as $name => $case) {
            foreach ([false, true] as $wrapped) {
                $cases[$name.'-wrapped-'.(int) $wrapped] = [...$case, $wrapped];
            }
        }

        return $cases;
    }

    #[DataProvider('sqlViews')]
    public function test_sql_errors_during_view_rendering_including_wrapped_exceptions_are_sanitized(string $action, bool $json, bool $debug, bool $wrapped): void
    {
        $eps = $this->errorScenario($debug);
        $before = $eps->getAttributes();
        $audits = Auditoria::query()->get()->toArray();
        $injected = false;
        View::composer('eps.'.$action, static function () use ($wrapped, &$injected): never {
            $injected = true;
            $failure = self::sqlFailure('select * from eps where nombre = ?');
            throw $wrapped ? new ViewException(self::SQL_WRAPPER, previous: $failure) : $failure;
        });
        $route = 'eps.'.$action;
        $url = route($route, in_array($action, ['show', 'edit'], true) ? ['eps' => $eps] : []);
        $response = $json ? $this->getJson($url) : $this->get($url);
        $this->assertTrue($injected);
        $this->assertSafeSqlFailure($response, $route, $json);
        $this->assertSame($before, $eps->refresh()->getAttributes());
        $this->assertSame($audits, Auditoria::query()->get()->toArray());
    }

    public static function nonSqlErrors(): array
    {
        return self::errorCases(['store', 'update']);
    }

    #[DataProvider('nonSqlErrors')]
    public function test_non_sql_errors_on_eps_routes_keep_normal_reporting_and_rollback(string $action, bool $json, bool $debug): void
    {
        $eps = $this->errorScenario($debug);
        $before = $eps->getAttributes();
        $audits = Auditoria::query()->get()->toArray();
        $failure = static function (): never {
            throw new RuntimeException('ERROR_NO_SQL_EPS_FICTICIO_OBSERVABLE');
        };
        $creating = $action === 'store';
        if ($creating) {
            Eps::created($failure);
        } else {
            Eps::updated($failure);
        }
        $url = route('eps.'.$action, $creating ? [] : ['eps' => $eps]);
        $method = $creating ? 'POST' : 'PUT';
        $data = $creating ? $this->datos() : $this->editar($eps, ['ciudad' => 'Yumbo']);
        try {
            $response = $json ? $this->json($method, $url, $data) : $this->call($method, $url, $data);
            $response->assertStatus(500);
            $log = $this->logContent();
            $this->assertStringContainsString('ERROR_NO_SQL_EPS_FICTICIO_OBSERVABLE', $log);
            $this->assertStringContainsString('RuntimeException', $log);
            $this->assertStringNotContainsString('Error de base de datos en catálogo EPS.', $log);
            $this->assertSame($before, $eps->refresh()->getAttributes());
            $this->assertDatabaseCount('eps', 1);
            $this->assertSame($audits, Auditoria::query()->get()->toArray());
        } finally {
            Eps::flushEventListeners();
        }
    }

    public static function unrelatedSqlErrors(): array
    {
        return self::errorCases(['control']);
    }

    #[DataProvider('unrelatedSqlErrors')]
    public function test_sql_errors_outside_eps_and_rf058_keep_default_reporting(string $action, bool $json, bool $debug): void
    {
        $this->errorScenario($debug);
        Route::middleware('web')->get('/eps-security-test-control', static function (): never {
            throw self::sqlFailure();
        })->name('eps-security-test.'.$action);
        $response = $json ? $this->getJson('/eps-security-test-control') : $this->get('/eps-security-test-control');
        $response->assertStatus(500);
        $log = $this->logContent();
        $this->assertStringContainsString(self::SQL_BINDING, $log);
        $this->assertStringContainsString(self::SQL_ORIGINAL, $log);
        $this->assertStringContainsString('QueryException', $log);
        $this->assertStringNotContainsString('Error de base de datos en catálogo EPS.', $log);
        $this->assertStringNotContainsString('Error de base de datos en RF-058.', $log);
    }

    public function test_permissions_seeder_is_idempotent_preserves_existing_assignments_and_all_v3_roles_work(): void
    {
        $this->seed(SecuritySeeder::class);
        $admin = Rol::query()->where('nombre', 'Administrador')->firstOrFail();
        $antes = $admin->permisos()->pluck('codigo')->all();
        $this->seed(EpsPermissionsSeeder::class);
        $this->seed(EpsPermissionsSeeder::class);
        foreach ($antes as $codigo) {
            $this->assertTrue($admin->permisos()->where('codigo', $codigo)->exists());
        }
        $this->assertSame(4, Permiso::query()->whereIn('codigo', ['RF-015', 'RF-016', 'RF-017', 'RF-018'])->count());
        foreach (['Administrador', 'Médico', 'Administrativo'] as $nombre) {
            $rol = Rol::query()->where('nombre', $nombre)->firstOrFail();
            foreach (['RF-015', 'RF-016', 'RF-017', 'RF-018'] as $codigo) {
                $this->assertTrue($rol->permisos()->where('codigo', $codigo)->exists());
            }
            $actor = User::factory()->create(['rol_id' => $rol->id]);
            $this->assertNull($actor->medico);
            $this->actingAs($actor)->post(route('eps.store'), $this->datos(['nit' => 'DEMO-'.$actor->id]))->assertSessionHasNoErrors();
            $eps = Eps::query()->latest('id')->first();
            $this->put(route('eps.update', $eps), $this->editar($eps, ['ciudad' => 'Yumbo']))->assertSessionHasNoErrors();
            $this->get(route('eps.show', $eps))->assertOk();
            $this->get(route('eps.index'))->assertOk();
            $this->get(route('eps.search'))->assertOk();
        }
        $this->assertFalse(Permiso::query()->whereIn('codigo', ['RF-019', 'RF-020', 'RF-058'])->exists());
    }

    public function test_eps_has_no_delete_or_independent_state_or_remission_routes(): void
    {
        $eps = Eps::factory()->create();
        $this->actingAs($this->operador())->delete(route('eps.show', $eps))->assertMethodNotAllowed();
        $this->patch('/eps/'.$eps->id.'/inactivar')->assertNotFound();
        $this->post('/eps/'.$eps->id.'/remisiones')->assertNotFound();
        $this->get('/eps/999999/edit')->assertNotFound();
        $this->put('/eps/999999', $this->datos())->assertNotFound();
        $this->assertDatabaseCount('eps', 1);
    }

    public function test_mysql_ddl_compiles_without_connecting_and_contains_engine_unique_and_binary_nit(): void
    {
        $connection = new MySqlConnection(static function (): never {
            throw new LogicException('Esta prueba no permite conexión a MySQL.');
        }, 'eps_sql_preview', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        $schema = Schema::getFacadeRoot();
        Schema::swap($connection->getSchemaBuilder());
        try {
            $queries = $connection->pretend(static function (): void {
                (require database_path('migrations/2026_10_25_190725_create_eps_table.php'))->up();
            });
            $sql = implode("\n", array_column($queries, 'query'));
            $this->assertStringContainsString('create table `eps`', $sql);
            $this->assertStringContainsString('engine = InnoDB', $sql);
            $this->assertStringContainsString("collate 'utf8mb4_bin'", $sql);
            $this->assertStringContainsString('unique', $sql);
            $this->assertStringNotContainsString('drop ', $sql);
        } finally {
            Schema::swap($schema);
        }
    }
}
