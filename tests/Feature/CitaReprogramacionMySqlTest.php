<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\CitaPermissionsSeeder;
use Database\Seeders\CitaReprogramacionPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CitaReprogramacionMySqlTest extends TestCase
{
    public static function operacionesConcurrentes(): array
    {
        return ['agendar el mismo horario' => [true], 'reprogramar la misma cita' => [false]];
    }

    #[DataProvider('operacionesConcurrentes')]
    public function test_revalida_despues_de_esperar_el_bloqueo_del_medico(bool $agendar): void
    {
        $db = DB::connection();
        if (! $this->app->environment('testing') || $this->app->configurationIsCached()
            || $db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'medisoft_rf026_qa'
            || ! $db->getConfig('unix_socket') || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requiere MySQL QA medisoft_rf026_qa por socket y PCNTL.');
        }

        $this->seed([CitaPermissionsSeeder::class, CitaReprogramacionPermissionsSeeder::class]);
        $rol = Rol::query()->where('nombre', 'Administrativo')->sole();
        $actor = User::factory()->for($rol)->create();
        $segundoActor = User::factory()->for($rol)->create();
        $cita = Cita::factory()->create(['fecha' => now('America/Bogota')->addDay()->toDateString()]);
        $cita->disponibilidad->update(['fecha' => $cita->fecha->toDateString()]);
        $ruta = route('citas.reprogramar.update', $cita);
        $solicitud = [
            'fecha' => $cita->fecha->toDateString(), 'hora_inicio' => '08:30', 'confirmacion' => '1',
            'version_horario' => $cita->refresh()->versionHorario(),
        ];
        DB::disconnect();

        $canales = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($canales);
        [$padre, $hijo] = $canales;
        $pid = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($padre);
            if (fgets($hijo) !== "iniciar\n") {
                exit(1);
            }

            $conexion = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            fwrite($hijo, $conexion."\n");
            $respuesta = $this->actingAs($segundoActor)->patchJson($ruta, $solicitud);
            fwrite($hijo, json_encode([$respuesta->getStatusCode(), $respuesta->json('errors.hora_inicio.0')], JSON_THROW_ON_ERROR)."\n");
            fclose($hijo);
            exit(0);
        }

        fclose($hijo);
        stream_set_timeout($padre, 10);
        try {
            DB::beginTransaction();
            $cita->medico()->lockForUpdate()->firstOrFail();
            fwrite($padre, "iniciar\n");
            $conexion = (int) fgets($padre);
            $this->assertGreaterThan(0, $conexion);
            $this->esperarBloqueo($conexion);

            $this->actingAs($actor);
            if ($agendar) {
                $respuesta = $this->postJson(route('citas.store'), [
                    'fecha' => $solicitud['fecha'], 'hora_inicio' => '08:30', 'confirmacion' => '1',
                    'medico_id' => $cita->medico_id, 'paciente_id' => $cita->paciente_id,
                    'motivo_consulta' => 'Reserva concurrente ficticia', 'valor_base' => '50000',
                ]);
            } else {
                $respuesta = $this->patchJson($ruta, [...$solicitud, 'hora_inicio' => '09:00']);
            }
            $respuesta->assertRedirect()->assertSessionHasNoErrors();
            DB::commit();

            $resultado = fgets($padre);
            $this->assertNotFalse($resultado);
            [$codigo, $error] = json_decode($resultado, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(422, $codigo);
            $this->assertStringContainsString($agendar ? 'El intervalo no está disponible' : 'La cita cambió', $error);
            $reservas = $cita->medico->citas()->orderBy('hora_inicio')->get();
            $horarios = $reservas->map(fn (Cita $reserva): string => $reserva->horario())->all();
            $fecha = $solicitud['fecha'];
            $this->assertSame($agendar
                ? ["$fecha 08:00:00–08:30:00", "$fecha 08:30:00–09:00:00"]
                : ["$fecha 09:00:00–09:30:00"], $horarios);
            $this->assertTrue($reservas->first()->is($cita));
            $this->assertSame($agendar ? 0 : 1, Auditoria::query()->where('accion', 'CITA_REPROGRAMADA')->where('registro_id', $cita->id)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($padre);
            pcntl_waitpid($pid, $estado);
        }

        $this->assertSame(0, pcntl_wexitstatus($estado));
    }

    private function esperarBloqueo(int $conexion): void
    {
        $limite = microtime(true) + 5;
        do {
            $espera = DB::selectOne(
                'SELECT 1 FROM performance_schema.data_lock_waits AS espera
                 JOIN performance_schema.threads AS hilo ON hilo.THREAD_ID = espera.REQUESTING_THREAD_ID
                 WHERE hilo.PROCESSLIST_ID = ?',
                [$conexion],
            );
            if ($espera !== null) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $limite);

        $this->fail('La segunda solicitud no llegó a esperar el bloqueo de la agenda.');
    }
}
