<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\NotificacionCita;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class NotificacionCitaMySqlTest extends TestCase
{
    private function comprobarAislamiento(): void
    {
        $db = DB::connection();
        $directorio = getenv('MEDISOFT_RF061_MYSQL_QA_DIR');
        if ($db->getDriverName() !== 'mysql' || ! $directorio) {
            $this->markTestSkipped('Requiere MySQL QA desechable para RF-061.');
        }

        $this->assertTrue($this->app->environment('testing'));
        $this->assertFalse($this->app->configurationIsCached());
        $this->assertTrue(function_exists('pcntl_fork'));
        $this->assertEmpty($db->getConfig('url'));
        $this->assertSame('medisoft_rf061_qa', $db->getDatabaseName());
        $this->assertSame(realpath($directorio), $directorio);
        $this->assertStringStartsWith(realpath(sys_get_temp_dir()).'/medisoft-rf061-mysql.', $directorio);
        $this->assertSame($directorio.'/mysql.sock', $db->getConfig('unix_socket'));
        $servidor = DB::selectOne('SELECT DATABASE() AS base, @@datadir AS datos, @@socket AS socket,
            @@skip_networking AS sin_red, @@transaction_isolation AS aislamiento');
        $this->assertSame('medisoft_rf061_qa', $servidor->base);
        $this->assertSame($directorio.'/data/', $servidor->datos);
        $this->assertSame($directorio.'/mysql.sock', $servidor->socket);
        $this->assertSame(1, (int) $servidor->sin_red);
        $this->assertSame('REPEATABLE-READ', $servidor->aislamiento);
    }

    public static function carreras(): array
    {
        return [
            'misma confirmación concurrente' => [false],
            'cancelación gana antes de confirmar' => [true],
        ];
    }

    #[DataProvider('carreras')]
    public function test_serializa_solicitudes_reales_sobre_la_cita(bool $cancelacion): void
    {
        $this->comprobarAislamiento();
        $rol = Rol::query()->firstOrCreate(['nombre' => 'Médico']);
        foreach (['RF-061', 'RF-027'] as $codigo) {
            $permiso = Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => 'QA aislada']);
            $rol->permisos()->syncWithoutDetaching([$permiso->id]);
        }
        $paciente = Paciente::factory()->create(['correo_electronico' => 'prueba-cita@example.test']);
        $cita = Cita::factory()->for($paciente)->create()->refresh();
        $actor = $cita->medico->user;
        $actor->update(['rol_id' => $rol->id]);
        $vista = $this->actingAs($actor)->get(route('notificaciones.citas.index', ['cita_id' => $cita->id]))
            ->assertOk()->viewData('vistaPrevia');
        $datos = ['confirmacion' => '1', 'vista_previa' => $vista['token']];
        $respuesta = $this->competir($actor, $cita, $datos, $cancelacion);

        $intentos = $cita->notificaciones();
        $auditorias = Auditoria::query()->where('entidad', 'NotificacionCita')->where('user_id', $actor->id);
        $this->assertSame($cancelacion ? 'CANCELADA' : 'PROGRAMADA', $cita->refresh()->estado);
        if ($cancelacion) {
            $this->assertSame(422, $respuesta['estado']);
            $this->assertSame(['vista_previa'], $respuesta['errores']);
            $this->assertSame(0, $intentos->count());
            $this->assertSame(0, $auditorias->count());
            $this->assertDatabaseHas('auditorias', ['accion' => 'CITA_CANCELADA', 'registro_id' => $cita->id, 'user_id' => $actor->id]);
        } else {
            $this->assertSame(302, $respuesta['estado']);
            $intento = $intentos->sole();
            $this->assertInstanceOf(NotificacionCita::class, $intento);
            $this->assertSame('SIMULADA', $intento->estado);
            $this->assertSame(route('notificaciones.citas.show', [$cita, $intento]), $respuesta['destino']);
            $this->assertSame($intento->id, $auditorias->sole()->registro_id);
        }
    }

    /** @param array{confirmacion: string, vista_previa: string} $datos */
    private function competir(User $actor, Cita $cita, array $datos, bool $cancelacion): array
    {
        DB::disconnect();
        $canales = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($canales);
        [$padre, $hijo] = $canales;
        $pid = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($padre);
            stream_set_timeout($hijo, 15);
            try {
                if (fgets($hijo) !== "iniciar\n") {
                    exit(1);
                }
                fwrite($hijo, DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n");
                $respuesta = $this->actingAs($actor)->postJson(route('notificaciones.citas.store', $cita), $datos);
                fwrite($hijo, json_encode([
                    'estado' => $respuesta->status(),
                    'errores' => $respuesta->status() === 422 ? array_keys($respuesta->json('errors')) : [],
                    'destino' => $respuesta->headers->get('Location'),
                ], JSON_THROW_ON_ERROR)."\n");
                exit(0);
            } catch (Throwable $e) {
                fwrite(STDERR, $e->getMessage().PHP_EOL);
                exit(1);
            }
        }

        fclose($hijo);
        stream_set_timeout($padre, 15);
        try {
            DB::beginTransaction();
            $primera = $cancelacion
                ? $this->actingAs($actor)->patch(route('citas.cancelar.update', $cita), [
                    'version_horario' => $cita->versionHorario(), 'confirmacion' => '1', 'motivo_cancelacion' => 'Cancelación ficticia QA',
                ])
                : $this->actingAs($actor)->post(route('notificaciones.citas.store', $cita), $datos);
            $primera->assertRedirect()->assertSessionHasNoErrors();
            fwrite($padre, "iniciar\n");
            $conexion = (int) fgets($padre);
            $this->assertGreaterThan(0, $conexion);
            $this->esperarBloqueo($conexion);
            DB::commit();
            $resultado = fgets($padre);
            $this->assertNotFalse($resultado);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($padre);
            pcntl_waitpid($pid, $estado);
        }
        $this->assertTrue(pcntl_wifexited($estado));
        $this->assertSame(0, pcntl_wexitstatus($estado));

        return json_decode($resultado, true, flags: JSON_THROW_ON_ERROR);
    }

    private function esperarBloqueo(int $conexion): void
    {
        $limite = microtime(true) + 5;
        do {
            $bloqueo = DB::selectOne('SELECT recurso.OBJECT_NAME AS tabla
                FROM performance_schema.data_lock_waits espera
                JOIN performance_schema.threads hilo ON hilo.THREAD_ID = espera.REQUESTING_THREAD_ID
                JOIN performance_schema.data_locks recurso ON recurso.ENGINE_LOCK_ID = espera.REQUESTING_ENGINE_LOCK_ID
                WHERE hilo.PROCESSLIST_ID = ?', [$conexion]);
            if ($bloqueo !== null) {
                $this->assertSame('citas', $bloqueo->tabla);

                return;
            }
            usleep(20000);
        } while (microtime(true) < $limite);

        $this->fail('La segunda solicitud no esperó el bloqueo de la cita.');
    }
}
