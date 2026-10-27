<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Carbon\Carbon;
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

class CitaParticularErrorTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'MOTIVO_CONSULTA_FICTICIO_PRIVADO_RF024';

    private const BINDING = 'BINDING_SQL_FICTICIO_PRIVADO_RF024';

    private const ORIGINAL = 'ERROR_SQL_FICTICIO_PRIVADO_RF024';

    private const WRAPPER = 'VISTA_ENVUELTA_FICTICIA_PRIVADA_RF024';

    private const RESPONSE = 'No fue posible completar la operación de citas particulares. Intente nuevamente.';

    private ?string $logPath = null;

    private User $actor;

    private Paciente $paciente;

    private DisponibilidadMedica $disponibilidad;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing') || config('database.default') !== 'sqlite') {
            throw new LogicException('Se requiere SQLite en memoria para estas pruebas.');
        }
        foreach ($this->connectionsToTransact() as $name) {
            $config = DB::connection($name)->getConfig();
            if ($config['driver'] !== 'sqlite' || $config['database'] !== ':memory:' || ! empty($config['url'])) {
                throw new LogicException('La conexión de pruebas no está aislada.');
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-26 07:00:00', 'America/Bogota'));
        $path = tempnam(sys_get_temp_dir(), 'medisoft-rf024-log-');
        if ($path === false) {
            throw new RuntimeException('No se pudo preparar el log temporal.');
        }
        $this->logPath = $path;
        config(['logging.default' => 'rf024_security_test', 'logging.channels.rf024_security_test' => [
            'driver' => 'single', 'path' => $path, 'level' => 'debug', 'replace_placeholders' => true,
        ]]);
        Log::forgetChannel('rf024_security_test');

        $rol = Rol::query()->create(['nombre' => 'Rol interno ficticio RF-024']);
        $rol->permisos()->attach(Permiso::query()->create(['codigo' => 'RF-024', 'descripcion' => 'Agendar cita particular ficticia']));
        $this->actor = User::factory()->create(['rol_id' => $rol->id]);
        $doctor = User::factory()->create(['tipo_usuario' => User::TIPO_USUARIO_MEDICO, 'cargo' => 'Médico']);
        $medico = $doctor->medico()->create(['registro_profesional' => 'DEMO-RF024', 'especialidad' => 'Medicina general']);
        $this->disponibilidad = DisponibilidadMedica::factory()->create([
            'medico_id' => $medico->id, 'fecha' => '2026-10-27',
            'hora_inicio' => '08:00:00', 'hora_fin' => '10:00:00', 'duracion_minutos' => 30,
        ]);
        $this->paciente = Paciente::factory()->create();
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        try {
            Cita::flushEventListeners();
            Auditoria::flushEventListeners();
            Log::forgetChannel('rf024_security_test');
            if ($this->logPath !== null && is_file($this->logPath)) {
                unlink($this->logPath);
            }
        } finally {
            parent::tearDown();
        }
    }

    private static function sqlFailure(): QueryException
    {
        return new QueryException('sqlite', 'insert into citas (motivo_consulta) values (?)', [self::BINDING], new PDOException('SQLSTATE[HY000] '.self::ORIGINAL));
    }

    /** @return array<string, int|string> */
    private function validInput(): array
    {
        return [
            'paciente_id' => $this->paciente->id, 'medico_id' => $this->disponibilidad->medico_id,
            'fecha' => '2026-10-27', 'hora_inicio' => '08:00',
            'motivo_consulta' => self::MOTIVO, 'valor_base' => '75000', 'confirmacion' => '1',
        ];
    }

    private function citaExistente(): Cita
    {
        return Cita::factory()->create([
            'paciente_id' => $this->paciente->id, 'medico_id' => $this->disponibilidad->medico_id,
            'disponibilidad_medica_id' => $this->disponibilidad->id, 'fecha' => '2026-10-27',
            'hora_inicio' => '08:00:00', 'hora_fin' => '08:30:00', 'modalidad' => 'PARTICULAR',
            'tipo_paciente_al_agendar' => 'PARTICULAR', 'motivo_consulta' => self::MOTIVO,
            'valor_base' => '75000', 'estado' => 'PROGRAMADA', 'created_by' => $this->actor->id,
        ]);
    }

    private function logContent(): string
    {
        $log = file_get_contents($this->logPath);
        $this->assertNotFalse($log);

        return $log;
    }

    private function assertNoSensitiveData(string $body): void
    {
        $surfaces = [$body, $this->logContent(), json_encode(session()->all(), JSON_THROW_ON_ERROR), Auditoria::query()->get()->toJson()];
        foreach ($surfaces as $surface) {
            foreach ([self::MOTIVO, self::BINDING, self::ORIGINAL, self::WRAPPER, 'SQLSTATE', 'insert into citas', 'QueryException', 'PDOException', 'ViewException', 'Stack trace'] as $marker) {
                $this->assertStringNotContainsString($marker, $surface);
            }
        }
    }

    private function assertSafeSqlResponse(TestResponse $response, string $route, bool $json): void
    {
        $response->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private')
            ->assertSessionMissing('status')->assertSessionMissing('errors')->assertSessionMissing('_old_input');
        if ($json) {
            $response->assertExactJson(['message' => self::RESPONSE]);
        } else {
            $response->assertContent(self::RESPONSE);
        }
        $this->assertNoSensitiveData($response->getContent());
        $log = $this->logContent();
        $this->assertSame(1, substr_count($log, 'Error de base de datos en citas particulares.'));
        $this->assertStringContainsString('"modulo":"RF-024"', $log);
        $this->assertStringContainsString('"operacion":"'.$route.'"', $log);
        $this->assertStringNotContainsString('"exception"', $log);
        $this->assertStringNotContainsString('"trace"', $log);
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function writeFailures(): array
    {
        $cases = [];
        foreach (['cita', 'auditoria'] as $stage) {
            foreach ([false, true] as $json) {
                foreach ([false, true] as $debug) {
                    $cases[$stage.'-'.($json ? 'json' : 'html').'-'.($debug ? 'debug' : 'production')] = [$stage, $json, $debug];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('writeFailures')]
    public function test_sql_write_and_audit_failures_are_sanitized_and_rolled_back(string $stage, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        $failure = static function (): never {
            throw self::sqlFailure();
        };
        if ($stage === 'cita') {
            Cita::created($failure);
        } else {
            Auditoria::created($failure);
        }
        $url = route('citas.store');
        $response = $json ? $this->postJson($url, $this->validInput()) : $this->post($url, $this->validInput());
        $this->assertSafeSqlResponse($response, 'citas.store', $json);
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function readFailures(): array
    {
        $cases = [];
        foreach (['create', 'confirmation'] as $action) {
            foreach ([false, true] as $json) {
                foreach ([false, true] as $debug) {
                    $cases[$action.'-'.($json ? 'json' : 'html').'-'.($debug ? 'debug' : 'production')] = [$action, $json, $debug];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('readFailures')]
    public function test_sql_read_and_binding_failures_are_sanitized(string $action, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        $cita = $action === 'confirmation' ? $this->citaExistente() : null;
        $injected = false;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$injected, $action): void {
            $table = $action === 'confirmation' ? '"citas"' : '"pacientes"';
            if (! $injected && str_contains($sql, 'select') && str_contains($sql, $table)) {
                $injected = true;
                throw self::sqlFailure();
            }
        });
        $route = 'citas.'.$action;
        $url = $action === 'confirmation' ? route($route, ['cita' => $cita]) : route($route);
        $response = $json ? $this->getJson($url) : $this->get($url);
        $this->assertTrue($injected, 'La petición no consultó la tabla prevista.');
        $this->assertSafeSqlResponse($response, $route, $json);
        $this->assertDatabaseCount('citas', $cita === null ? 0 : 1);
    }

    #[DataProvider('readFailures')]
    public function test_view_exceptions_wrapping_sql_failures_are_sanitized(string $action, bool $json, bool $debug): void
    {
        config(['app.debug' => $debug]);
        $cita = $action === 'confirmation' ? $this->citaExistente() : null;
        View::composer('citas.particulares.'.$action, static function (): never {
            throw new ViewException(self::WRAPPER, previous: self::sqlFailure());
        });
        $route = 'citas.'.$action;
        $url = $action === 'confirmation' ? route($route, ['cita' => $cita]) : route($route);
        $this->assertSafeSqlResponse($json ? $this->getJson($url) : $this->get($url), $route, $json);
        $this->assertDatabaseCount('citas', $cita === null ? 0 : 1);
    }

    public function test_non_sql_failure_on_store_keeps_default_reporting_and_rolls_back(): void
    {
        config(['app.debug' => false]);
        Cita::created(static function (): never {
            throw new RuntimeException('ERROR_NO_SQL_FICTICIO_OBSERVABLE_RF024');
        });
        $this->post(route('citas.store'), $this->validInput())->assertStatus(500);
        $this->assertStringContainsString('ERROR_NO_SQL_FICTICIO_OBSERVABLE_RF024', $this->logContent());
        $this->assertStringNotContainsString('Error de base de datos en citas particulares.', $this->logContent());
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_sql_failure_on_unrelated_route_keeps_default_reporting(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/rf024-control-no-citas', static function (): never {
            throw self::sqlFailure();
        })->name('rf024.control.no-citas');
        $this->get('/rf024-control-no-citas')->assertStatus(500);
        $this->assertStringContainsString(self::BINDING, $this->logContent());
        $this->assertStringContainsString(self::ORIGINAL, $this->logContent());
        $this->assertStringNotContainsString('Error de base de datos en citas particulares.', $this->logContent());
    }

    public function test_forbidden_confirmation_does_not_expose_private_motive(): void
    {
        config(['app.debug' => false]);
        $cita = $this->citaExistente();
        $other = User::factory()->create(['rol_id' => $this->actor->rol_id]);
        $this->actingAs($other);
        $response = $this->get(route('citas.confirmation', ['cita' => $cita]));
        $response->assertForbidden();
        $this->assertStringNotContainsString(self::MOTIVO, $response->getContent());
        $this->assertStringNotContainsString(self::MOTIVO, $this->logContent());
        $this->assertDatabaseCount('citas', 1);
    }

    public function test_forbidden_store_does_not_expose_submitted_motive(): void
    {
        config(['app.debug' => false]);
        $roleWithoutPermission = Rol::query()->create(['nombre' => 'Rol sin RF-024']);
        $this->actingAs(User::factory()->create(['rol_id' => $roleWithoutPermission->id]));

        $response = $this->post(route('citas.store'), $this->validInput());

        $response->assertForbidden();
        $this->assertStringNotContainsString(self::MOTIVO, $response->getContent());
        $this->assertStringNotContainsString(self::MOTIVO, $this->logContent());
        $this->assertDatabaseCount('citas', 0);
    }
}
