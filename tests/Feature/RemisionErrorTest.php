<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\CambioEstadoRemision;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Illuminate\View\ViewException;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RemisionErrorTest extends TestCase
{
    use RefreshDatabase;

    private const BINDING = 'REMISION_CONTENIDO_FICTICIO_BINDING';

    private const ORIGINAL = 'REMISION_MENSAJE_ORIGINAL_FICTICIO';

    private const WRAPPER = 'REMISION_ENVUELTA_FICTICIA';

    private const RESPONSE = 'No fue posible completar la operación de remisiones. Intente nuevamente.';

    private ?string $logPath = null;

    private RemisionEps $remision;

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
        $path = tempnam(sys_get_temp_dir(), 'medisoft-remision-log-');
        if ($path === false) {
            throw new RuntimeException('No se pudo crear el log temporal.');
        }
        $this->logPath = $path;
        config(['logging.default' => 'remision_test', 'logging.channels.remision_test' => ['driver' => 'single', 'path' => $path, 'level' => 'debug', 'replace_placeholders' => true]]);
        Log::forgetChannel('remision_test');
        $rol = Rol::query()->create(['nombre' => 'Rol ficticio de remisiones']);
        foreach (['RF-019', 'RF-020', 'RF-021', 'RF-022', 'RF-023'] as $codigo) {
            $rol->permisos()->attach(Permiso::query()->create(['codigo' => $codigo, 'descripcion' => 'Permiso ficticio']));
        }
        $this->actingAs(User::factory()->create(['rol_id' => $rol->id]));
        $this->remision = RemisionEps::factory()->create(['fecha_remision' => '2026-10-01', 'vigencia_hasta' => '2026-10-24', 'estado' => 'VENCIDA'])->refresh();
        Auditoria::query()->create(['user_id' => $this->app['auth']->id(), 'accion' => 'REMISION_CREADA', 'entidad' => 'RemisionEPS', 'registro_id' => $this->remision->id, 'fecha_hora' => now(), 'resultado' => 'EXITO', 'descripcion' => 'Evento previo ficticio.']);
    }

    protected function tearDown(): void
    {
        try {
            RemisionEps::flushEventListeners();
            CambioEstadoRemision::flushEventListeners();
            Auditoria::flushEventListeners();
            Log::forgetChannel('remision_test');
            if ($this->logPath !== null && is_file($this->logPath)) {
                unlink($this->logPath);
            }
        } finally {
            parent::tearDown();
        }
    }

    private static function sqlFailure(): QueryException
    {
        return new QueryException('sqlite', 'insert into remisiones_eps (observacion) values (?)', [self::BINDING], new PDOException('SQLSTATE[HY000] '.self::ORIGINAL));
    }

    private function logContent(): string
    {
        $log = file_get_contents($this->logPath);
        $this->assertNotFalse($log);

        return $log;
    }

    private function assertSafeFailure(TestResponse $response, string $route, bool $json): void
    {
        $response->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private')->assertSessionMissing('status')->assertSessionMissing('errors')->assertSessionMissing('_old_input');
        if ($json) {
            $response->assertExactJson(['message' => self::RESPONSE]);
        } else {
            $response->assertContent(self::RESPONSE);
        }
        $log = $this->logContent();
        $surfaces = [$response->getContent(), $log, json_encode($this->app['session']->all(), JSON_THROW_ON_ERROR), Auditoria::query()->get()->toJson()];
        foreach ([self::BINDING, self::ORIGINAL, self::WRAPPER, 'SQLSTATE', 'insert into remisiones_eps', 'PDOException', 'QueryException', 'ViewException', 'Stack trace'] as $sensitive) {
            foreach ($surfaces as $surface) {
                $this->assertStringNotContainsString($sensitive, $surface);
            }
        }
        $this->assertSame(1, substr_count($log, 'Error de base de datos en remisiones EPS.'));
        $this->assertStringContainsString('"modulo":"RemisionesEPS"', $log);
        $this->assertStringContainsString('"operacion":"'.$route.'"', $log);
        foreach (['"exception"', '"trace"', 'Error de base de datos en RF-058.', 'Error de base de datos en catálogo EPS.'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $log);
        }
    }

    private static function cases(array $actions): array
    {
        $cases = [];
        foreach ($actions as $action) {
            foreach ([false, true] as $json) {
                foreach ([false, true] as $debug) {
                    $cases[$action.'-'.(int) $json.'-'.(int) $debug] = [$action, $json, $debug];
                }
            }
        }

        return $cases;
    }

    public static function writes(): array
    {
        return self::cases(['store', 'update', 'state', 'history', 'revive_history', 'audit_store', 'audit_update', 'audit_state', 'audit_revive']);
    }

    #[DataProvider('writes')]
    public function test_sql_write_failures_are_sanitized_by_real_handler_and_logging_and_rollback_all_data(string $action, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        $before = $this->remision->getAttributes();
        $audits = Auditoria::query()->get()->toArray();
        $failure = static function (): never {
            throw self::sqlFailure();
        };
        if (str_starts_with($action, 'audit_')) {
            Auditoria::created($failure);
        } elseif (in_array($action, ['history', 'revive_history'], true)) {
            CambioEstadoRemision::created($failure);
        } elseif ($action === 'store') {
            RemisionEps::created($failure);
        } else {
            RemisionEps::updated($failure);
        }
        $creating = in_array($action, ['store', 'audit_store'], true);
        $cancelling = in_array($action, ['state', 'history', 'audit_state'], true);
        $reviving = in_array($action, ['revive_history', 'audit_revive'], true);
        $route = $creating ? 'remisiones.store' : ($cancelling ? 'remisiones.changeState' : 'remisiones.update');
        $url = route($route, $creating ? [] : ['remision' => $this->remision]);
        $method = $creating ? 'POST' : ($cancelling ? 'PATCH' : 'PUT');
        $data = $creating ? ['paciente_id' => $this->remision->paciente_id, 'eps_id' => $this->remision->eps_id, 'numero_remision' => 'FICTICIO-NUEVO', 'fecha_remision' => '2026-10-25', 'vigencia_hasta' => '2026-11-25', 'servicio_autorizado' => self::BINDING]
            : ($cancelling ? ['estado' => 'ANULADA', 'motivo' => self::BINDING, 'confirmacion' => '1'] : ['servicio_autorizado' => $this->remision->servicio_autorizado, 'vigencia_hasta' => $reviving ? '2026-11-25' : '2026-10-24', 'observacion' => self::BINDING, 'motivo' => $reviving ? self::BINDING : null]);
        $response = $json ? $this->json($method, $url, $data) : $this->call($method, $url, $data);
        $this->assertSafeFailure($response, $route, $json);
        $this->assertSame($before, $this->remision->refresh()->getAttributes());
        $this->assertDatabaseCount('remisiones_eps', 1);
        $this->assertDatabaseCount('cambios_estado_remision', 0);
        $this->assertSame($audits, Auditoria::query()->get()->toArray());
    }

    public static function reads(): array
    {
        return self::cases(['index', 'search', 'show', 'edit', 'state', 'create']);
    }

    #[DataProvider('reads')]
    public function test_sql_read_or_binding_errors_are_safe_on_all_get_routes(string $action, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        $before = $this->remision->getAttributes();
        $audits = Auditoria::query()->get()->toArray();
        $injected = false;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$injected): void {
            if (! $injected && str_contains($sql, 'select') && (str_contains($sql, '"remisiones_eps"') || str_contains($sql, '"pacientes"') || str_contains($sql, '"eps"'))) {
                $injected = true;
                throw self::sqlFailure();
            }
        });
        $url = route('remisiones.'.$action, in_array($action, ['show', 'edit', 'state'], true) ? ['remision' => $this->remision] : ($action === 'search' ? ['buscar' => 'FICTICIO'] : []));
        $response = $json ? $this->getJson($url) : $this->get($url);
        $this->assertTrue($injected);
        $this->assertSafeFailure($response, 'remisiones.'.$action, $json);
        $this->assertSame($before, $this->remision->refresh()->getAttributes());
        $this->assertSame($audits, Auditoria::query()->get()->toArray());
    }

    public static function views(): array
    {
        $cases = [];
        foreach (self::reads() as $name => $case) {
            foreach ([false, true] as $wrapped) {
                $cases[$name.'-wrapped-'.(int) $wrapped] = [...$case, $wrapped];
            }
        }

        return $cases;
    }

    #[DataProvider('views')]
    public function test_sql_view_errors_and_wrapped_exceptions_never_leak(string $action, bool $json, bool $debug, bool $wrapped): void
    {
        config(['app.debug' => $debug]);
        View::composer('remisiones.'.$action, static function () use ($wrapped): never {
            $error = self::sqlFailure();
            throw $wrapped ? new ViewException(self::WRAPPER, previous: $error) : $error;
        });
        $url = route('remisiones.'.$action, in_array($action, ['show', 'edit', 'state'], true) ? ['remision' => $this->remision] : []);
        $response = $json ? $this->getJson($url) : $this->get($url);
        $this->assertSafeFailure($response, 'remisiones.'.$action, $json);
        $this->assertDatabaseCount('remisiones_eps', 1);
        $this->assertDatabaseCount('auditorias', 1);
        $this->assertDatabaseCount('cambios_estado_remision', 0);
    }

    public static function controlCases(): array
    {
        return self::cases(['control']);
    }

    #[DataProvider('controlCases')]
    public function test_non_sql_errors_on_remission_routes_keep_normal_reporting(string $action, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        RemisionEps::created(static function (): never {
            throw new RuntimeException('ERROR_NO_SQL_FICTICIO_OBSERVABLE');
        });
        $data = ['paciente_id' => $this->remision->paciente_id, 'eps_id' => $this->remision->eps_id, 'numero_remision' => 'FICTICIO-NUEVO', 'fecha_remision' => '2026-10-25', 'vigencia_hasta' => '2026-11-25', 'servicio_autorizado' => 'Servicio ficticio'];
        $response = $json ? $this->postJson(route('remisiones.store'), $data) : $this->post(route('remisiones.store'), $data);
        $response->assertStatus(500);
        $log = $this->logContent();
        $this->assertStringContainsString('ERROR_NO_SQL_FICTICIO_OBSERVABLE', $log);
        $this->assertStringNotContainsString('Error de base de datos en remisiones EPS.', $log);
        $this->assertDatabaseCount('remisiones_eps', 1);
        $this->assertDatabaseCount('auditorias', 1);
    }

    #[DataProvider('controlCases')]
    public function test_unrelated_sql_errors_keep_default_reporting(string $action, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        Route::middleware('web')->get('/remision-test-control', static function (): never {
            throw self::sqlFailure();
        })->name('remision_test.'.$action);
        ($json ? $this->getJson('/remision-test-control') : $this->get('/remision-test-control'))->assertStatus(500);
        $log = $this->logContent();
        $this->assertStringContainsString(self::BINDING, $log);
        $this->assertStringContainsString(self::ORIGINAL, $log);
        $this->assertStringNotContainsString('Error de base de datos en remisiones EPS.', $log);
    }
}
