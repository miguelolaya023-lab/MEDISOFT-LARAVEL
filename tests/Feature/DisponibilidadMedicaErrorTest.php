<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\DisponibilidadMedica;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

class DisponibilidadMedicaErrorTest extends TestCase
{
    use RefreshDatabase;

    private const BINDING = 'DISPONIBILIDAD_BINDING_FICTICIO';

    private const ORIGINAL = 'DISPONIBILIDAD_ERROR_ORIGINAL_FICTICIO';

    private const WRAPPER = 'DISPONIBILIDAD_ENVUELTA_FICTICIA';

    private const RESPONSE = 'No fue posible completar la operación de disponibilidad médica. Intente nuevamente.';

    private ?string $logPath = null;

    private DisponibilidadMedica $bloque;

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
        $path = tempnam(sys_get_temp_dir(), 'medisoft-disponibilidad-log-');
        if ($path === false) {
            throw new RuntimeException('No se pudo preparar el log aislado.');
        }
        $this->logPath = $path;
        config(['logging.default' => 'rf060_test', 'logging.channels.rf060_test' => ['driver' => 'single', 'path' => $path, 'level' => 'debug', 'replace_placeholders' => true]]);
        Log::forgetChannel('rf060_test');
        $rol = Rol::query()->create(['nombre' => 'Rol profesional ficticio']);
        $rol->permisos()->attach(Permiso::query()->create(['codigo' => 'RF-060', 'descripcion' => 'Permiso ficticio']));
        $actor = User::factory()->create(['rol_id' => $rol->id]);
        $medico = $actor->medico()->create(['registro_profesional' => 'DEMO-060', 'especialidad' => 'Medicina general']);
        $this->actingAs($actor);
        $this->bloque = DisponibilidadMedica::factory()->create(['medico_id' => $medico->id, 'fecha' => '2026-10-27'])->refresh();
    }

    protected function tearDown(): void
    {
        try {
            DisponibilidadMedica::flushEventListeners();
            Auditoria::flushEventListeners();
            Log::forgetChannel('rf060_test');
            if ($this->logPath !== null && is_file($this->logPath)) {
                unlink($this->logPath);
            }
        } finally {
            parent::tearDown();
        }
    }

    private static function sqlFailure(): QueryException
    {
        return new QueryException('sqlite', 'insert into disponibilidades_medicas (hora_inicio) values (?)', [self::BINDING], new PDOException('SQLSTATE[HY000] '.self::ORIGINAL));
    }

    private function logContent(): string
    {
        $log = file_get_contents($this->logPath);
        $this->assertNotFalse($log);

        return $log;
    }

    private function assertSafe(TestResponse $response, string $route, bool $json): void
    {
        $response->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private')->assertSessionMissing('status')->assertSessionMissing('errors')->assertSessionMissing('_old_input');
        if ($json) {
            $response->assertExactJson(['message' => self::RESPONSE]);
        } else {
            $response->assertContent(self::RESPONSE);
        }
        $log = $this->logContent();
        foreach ([$response->getContent(), $log, json_encode($this->app['session']->all(), JSON_THROW_ON_ERROR), Auditoria::query()->get()->toJson()] as $surface) {
            foreach ([self::BINDING, self::ORIGINAL, self::WRAPPER, 'SQLSTATE', 'insert into disponibilidades_medicas', 'QueryException', 'PDOException', 'ViewException', 'Stack trace'] as $marker) {
                $this->assertStringNotContainsString($marker, $surface);
            }
        }
        $this->assertSame(1, substr_count($log, 'Error de base de datos en disponibilidad médica.'));
        $this->assertStringContainsString('"modulo":"RF-060"', $log);
        $this->assertStringContainsString('"operacion":"'.$route.'"', $log);
        foreach (['"exception"', '"trace"', 'Error de base de datos en RF-058.', 'Error de base de datos en catálogo EPS.', 'Error de base de datos en remisiones EPS.'] as $marker) {
            $this->assertStringNotContainsString($marker, $log);
        }
    }

    private static function cases(array $actions): array
    {
        $cases = [];
        foreach ($actions as $action) {
            foreach ([false, true] as $json) {
                foreach ([false, true] as $debug) {
                    $cases[] = [$action, $json, $debug];
                }
            }
        }

        return $cases;
    }

    public static function writes(): array
    {
        return self::cases(['store', 'update', 'audit_store', 'audit_update', 'second_audit']);
    }

    #[DataProvider('writes')]
    public function test_sql_write_and_audit_failures_use_real_handler_logs_and_full_rollback(string $action, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        $antes = $this->bloque->getAttributes();
        $numero = 0;
        $error = static function () use (&$numero, $action): void {
            $numero++;
            if ($action !== 'second_audit' || $numero === 2) {
                throw self::sqlFailure();
            }
        };
        if (str_contains($action, 'audit')) {
            Auditoria::created($error);
        } elseif ($action === 'store') {
            DisponibilidadMedica::created($error);
        } else {
            DisponibilidadMedica::updated($error);
        }
        $store = in_array($action, ['store', 'audit_store', 'second_audit'], true);
        $route = $store ? 'disponibilidad.store' : 'disponibilidad.update';
        $data = ['hora_inicio' => '08:00', 'hora_fin' => '11:00', 'duracion_minutos' => 30, 'confirmacion' => '1',
            ...($store ? ['fechas' => ['2026-10-28', '2026-10-29']] : ['fecha' => '2026-10-27'])];
        $url = route($route, $store ? [] : ['disponibilidad' => $this->bloque]);
        $response = $json ? $this->json($store ? 'POST' : 'PUT', $url, $data) : $this->call($store ? 'POST' : 'PUT', $url, $data);
        $this->assertSafe($response, $route, $json);
        $this->assertDatabaseCount('disponibilidades_medicas', 1);
        $this->assertDatabaseCount('auditorias', 0);
        $this->assertSame($antes, $this->bloque->refresh()->getAttributes());
    }

    public static function reads(): array
    {
        return self::cases(['index', 'edit']);
    }

    #[DataProvider('reads')]
    public function test_read_and_binding_sql_errors_are_safe_in_html_json_and_debug_modes(string $action, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        $injected = false;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$injected): void {
            if (! $injected && str_contains($sql, 'select') && str_contains($sql, '"disponibilidades_medicas"')) {
                $injected = true;
                throw self::sqlFailure();
            }
        });
        $route = 'disponibilidad.'.$action;
        $url = route($route, $action === 'index' ? [] : ['disponibilidad' => $this->bloque]);
        $response = $json ? $this->getJson($url) : $this->get($url);
        $this->assertTrue($injected);
        $this->assertSafe($response, $route, $json);
        $this->assertDatabaseCount('disponibilidades_medicas', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('reads')]
    public function test_wrapped_sql_view_errors_are_sanitized(string $action, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        View::composer('disponibilidad.'.$action, static function (): never {
            throw new ViewException(self::WRAPPER, previous: self::sqlFailure());
        });
        $route = 'disponibilidad.'.$action;
        $url = route($route, $action === 'index' ? [] : ['disponibilidad' => $this->bloque]);
        $this->assertSafe($json ? $this->getJson($url) : $this->get($url), $route, $json);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function controls(): array
    {
        return self::cases(['control']);
    }

    #[DataProvider('controls')]
    public function test_non_sql_module_errors_keep_default_reporting_and_rollback(string $action, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        DisponibilidadMedica::updated(static function (): never {
            throw new RuntimeException('ERROR_NO_SQL_FICTICIO_OBSERVABLE_RF060');
        });
        $antes = $this->bloque->getAttributes();
        $data = ['fecha' => '2026-10-27', 'hora_inicio' => '08:00', 'hora_fin' => '11:00', 'duracion_minutos' => 30, 'confirmacion' => '1'];
        $url = route('disponibilidad.update', $this->bloque);
        ($json ? $this->putJson($url, $data) : $this->put($url, $data))->assertStatus(500);
        $this->assertStringContainsString('ERROR_NO_SQL_FICTICIO_OBSERVABLE_RF060', $this->logContent());
        $this->assertStringNotContainsString('Error de base de datos en disponibilidad médica.', $this->logContent());
        $this->assertSame($antes, $this->bloque->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('controls')]
    public function test_unrelated_sql_errors_are_still_reported_normally(string $action, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        Route::middleware('web')->get('/rf060-control', static function (): never {
            throw self::sqlFailure();
        })->name('rf060.control');
        ($json ? $this->getJson('/rf060-control') : $this->get('/rf060-control'))->assertStatus(500);
        $this->assertStringContainsString(self::BINDING, $this->logContent());
        $this->assertStringContainsString(self::ORIGINAL, $this->logContent());
        $this->assertStringNotContainsString('Error de base de datos en disponibilidad médica.', $this->logContent());
    }
}
