<?php

namespace Tests\Feature;

use App\Models\AntecedenteClinico;
use App\Models\Auditoria;
use App\Models\Paciente;
use App\Models\RevisionAntecedenteClinico;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\AntecedentePermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\View\ViewException;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** B1: HTTP, handler y canal single reales; únicamente datos ficticios. */
class AntecedenteClinicoErrorTest extends TestCase
{
    use RefreshDatabase;

    private const ACTUAL = 'MARCADOR_CLINICO_ACTUAL_FICTICIO_RF058';

    private const HISTORICO = 'MARCADOR_CLINICO_HISTORICO_FICTICIO_RF058';

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

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'medisoft-rf058-log-');
        if ($path === false) {
            throw new RuntimeException('No se pudo preparar el log aislado.');
        }
        $this->logPath = $path;
        config([
            'app.debug' => true,
            'logging.default' => 'rf058_security_test',
            'logging.channels.rf058_security_test' => [
                'driver' => 'single', 'path' => $path, 'level' => 'debug', 'replace_placeholders' => true,
            ],
        ]);
        Log::forgetChannel('rf058_security_test');
    }

    protected function tearDown(): void
    {
        try {
            Log::forgetChannel('rf058_security_test');
            if ($this->logPath !== null && is_file($this->logPath)) {
                unlink($this->logPath);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return array{User, Paciente, AntecedenteClinico} */
    private function escenario(): array
    {
        $this->seed(AntecedentePermissionsSeeder::class);
        $user = User::factory()->create(['rol_id' => Rol::query()->where('nombre', 'Médico')->value('id')]);
        $user->medico()->create(['registro_profesional' => 'DEMO-RF058', 'especialidad' => 'Medicina general']);
        $paciente = Paciente::factory()->create();
        $historia = $paciente->historiaClinica()->create(['fecha_apertura' => now()->subMonth()]);
        $registro = AntecedenteClinico::factory()->create([
            'historia_clinica_id' => $historia->id, 'medico_id' => $user->medico->id,
            'descripcion' => self::HISTORICO, 'tipo' => 'Inicial', 'fecha_registro' => now()->subDay(),
        ])->refresh();
        $this->actingAs($user);

        return [$user, $paciente->refresh(), $registro];
    }

    private static function falloSql(): QueryException
    {
        return new QueryException(
            'sqlite', 'insert into antecedentes_clinicos (descripcion) values (?)', [self::ACTUAL],
            new PDOException('SQLSTATE[HY000]: error ficticio '.self::HISTORICO),
        );
    }

    private function logContent(): string
    {
        $contenido = file_get_contents($this->logPath);
        $this->assertNotFalse($contenido);

        return $contenido;
    }

    private function assertNoClinicalLeak(string $body, string $ruta): void
    {
        $log = $this->logContent();
        $session = json_encode($this->app['session']->all(), JSON_THROW_ON_ERROR);
        foreach ([self::ACTUAL, self::HISTORICO, 'SQLSTATE', 'insert into antecedentes_clinicos', 'PDOException', 'QueryException', 'Stack trace'] as $sensible) {
            $this->assertStringNotContainsString($sensible, $body);
            $this->assertStringNotContainsString($sensible, $log);
            $this->assertStringNotContainsString($sensible, $session);
            foreach (Auditoria::query()->get() as $audit) {
                $this->assertStringNotContainsString($sensible, json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR));
            }
        }
        $this->assertSame(1, substr_count($log, 'Error de base de datos en RF-058.'));
        $this->assertStringContainsString('"modulo":"RF-058"', $log);
        $this->assertStringContainsString('"operacion":"'.$ruta.'"', $log);
        $this->assertStringNotContainsString('"exception"', $log);
        $this->assertStringNotContainsString('"trace"', $log);
    }

    public static function fallosEscritura(): array
    {
        $casos = [];
        foreach (['registro', 'revision', 'correccion', 'auditoria_registro', 'auditoria_correccion'] as $etapa) {
            foreach ([false, true] as $json) {
                $casos[] = [$etapa, $json];
            }
        }

        return $casos;
    }

    #[DataProvider('fallosEscritura')]
    public function test_sql_write_error_uses_real_handler_and_file_logging_without_leaks_and_rolls_back(string $etapa, bool $json): void
    {
        [, $paciente, $registro] = $this->escenario();
        $antes = $registro->getAttributes();
        $pacienteAntes = $paciente->getAttributes();
        $historia = $paciente->historiaClinica->getAttributes();
        $fallo = static function (): void {
            throw self::falloSql();
        };
        match ($etapa) {
            'registro' => AntecedenteClinico::created($fallo),
            'revision' => RevisionAntecedenteClinico::created($fallo),
            'correccion' => AntecedenteClinico::updated($fallo),
            default => Auditoria::created($fallo),
        };
        $crear = in_array($etapa, ['registro', 'auditoria_registro'], true);
        $ruta = $crear ? 'antecedentes.store' : 'antecedentes.update';
        $url = route($ruta, ['paciente' => $paciente, ...($crear ? [] : ['antecedente' => $registro])]);
        $method = $crear ? 'POST' : 'PUT';
        $datos = ['descripcion' => self::ACTUAL, 'tipo' => 'Corrección ficticia'];
        try {
            $response = $json ? $this->json($method, $url, $datos) : $this->call($method, $url, $datos);
            $response->assertStatus(503)->assertSessionMissing('status')->assertSessionMissing('errors')->assertSessionMissing('_old_input');
            if ($json) {
                $response->assertExactJson(['message' => 'No fue posible completar la operación de antecedentes clínicos. Intente nuevamente.']);
            } else {
                $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
            }
            $this->assertNoClinicalLeak($response->getContent(), $ruta);
            $this->assertSame($antes, $registro->refresh()->getAttributes());
            $this->assertSame($pacienteAntes, $paciente->refresh()->getAttributes());
            $this->assertSame($historia, $paciente->historiaClinica->getAttributes());
            $this->assertDatabaseCount('antecedentes_clinicos', 1);
            $this->assertDatabaseCount('antecedente_clinico_revisiones', 0);
            $this->assertDatabaseCount('auditorias', 0);
        } finally {
            AntecedenteClinico::flushEventListeners();
            RevisionAntecedenteClinico::flushEventListeners();
            Auditoria::flushEventListeners();
        }
    }

    public static function lecturas(): array
    {
        return [['index', false], ['index', true], ['edit', false], ['edit', true]];
    }

    #[DataProvider('lecturas')]
    public function test_sql_read_error_is_sanitized_for_authorized_clinical_routes(string $accion, bool $debug): void
    {
        config(['app.debug' => $debug]);
        [, $paciente, $registro] = $this->escenario();
        DB::connection()->beforeExecuting(static function (string $sql): void {
            if (str_contains($sql, 'select') && str_contains($sql, '"antecedentes_clinicos"')) {
                throw self::falloSql();
            }
        });
        $ruta = 'antecedentes.'.$accion;
        $response = $this->get(route($ruta, ['paciente' => $paciente, ...($accion === 'edit' ? ['antecedente' => $registro] : [])]));
        $response->assertStatus(503);
        $this->assertNoClinicalLeak($response->getContent(), $ruta);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_sql_error_wrapped_in_clinical_view_exception_is_sanitized(): void
    {
        [, $paciente] = $this->escenario();
        View::composer('antecedentes.index', static function (): void {
            throw new ViewException('Vista ficticia '.self::HISTORICO, previous: self::falloSql());
        });
        $response = $this->get(route('antecedentes.index', $paciente))->assertStatus(503);
        $this->assertNoClinicalLeak($response->getContent(), 'antecedentes.index');
    }

    public function test_non_clinical_sql_errors_keep_default_reporting_and_are_not_silenced(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/rf058-test-no-clinico', static function (): never {
            throw new QueryException('sqlite', 'select ?', ['ERROR_TECNICO_FICTICIO_NO_CLINICO'], new PDOException('Fallo técnico ficticio'));
        })->name('rf058.test.no-clinico');
        $this->get('/rf058-test-no-clinico')->assertStatus(500);
        $log = $this->logContent();
        $this->assertStringContainsString('ERROR_TECNICO_FICTICIO_NO_CLINICO', $log);
        $this->assertStringContainsString('QueryException', $log);
        $this->assertStringNotContainsString('Error de base de datos en RF-058.', $log);
    }

    public function test_non_sql_errors_in_clinical_route_remain_observable(): void
    {
        config(['app.debug' => false]);
        [, $paciente] = $this->escenario();
        AntecedenteClinico::created(static function (): void {
            throw new RuntimeException('ERROR_NO_SQL_FICTICIO_OBSERVABLE');
        });
        try {
            $this->post(route('antecedentes.store', $paciente), ['descripcion' => 'Texto ficticio'])->assertStatus(500);
            $log = $this->logContent();
            $this->assertStringContainsString('ERROR_NO_SQL_FICTICIO_OBSERVABLE', $log);
            $this->assertStringNotContainsString('Error de base de datos en RF-058.', $log);
            $this->assertDatabaseCount('antecedentes_clinicos', 1);
            $this->assertDatabaseCount('auditorias', 0);
        } finally {
            AntecedenteClinico::flushEventListeners();
        }
    }
}
