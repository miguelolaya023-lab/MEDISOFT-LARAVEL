<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Eps;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\CicloClinicoPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class CicloClinicoMySqlTest extends TestCase
{
    /** @return array<string, array{string, string, ?string}> */
    public static function carreras(): array
    {
        return [
            'dos cierres de la misma atención EPS' => ['finalizar', 'finalizar', null],
            'cierre antes de editar motivo' => ['finalizar', 'motivo', 'atencion'],
            'edición de motivo antes del cierre' => ['motivo', 'finalizar', 'version'],
            'cierre antes de editar diagnóstico' => ['finalizar', 'diagnostico', 'atencion'],
            'edición de diagnóstico antes del cierre' => ['diagnostico', 'finalizar', 'version'],
            'dos ediciones del mismo motivo' => ['motivo', 'motivo', 'version'],
            'dos ediciones del mismo diagnóstico' => ['diagnostico', 'diagnostico', 'version'],
            'dos campos independientes' => ['motivo', 'diagnostico', null],
        ];
    }

    private function comprobarAislamiento(): void
    {
        $db = DB::connection();
        $directorio = getenv('MEDISOFT_CICLO_MYSQL_QA_DIR');
        if ($db->getDriverName() !== 'mysql' || ! $directorio) {
            $this->markTestSkipped('Requiere una copia MySQL QA aislada y MEDISOFT_CICLO_MYSQL_QA_DIR.');
        }
        $this->assertTrue($this->app->environment('testing'));
        $this->assertFalse($this->app->configurationIsCached());
        $this->assertTrue(function_exists('pcntl_fork'));
        $this->assertEmpty($db->getConfig('url'));
        $this->assertMatchesRegularExpression('/^medisoft_[a-z0-9_]+_qa$/D', $db->getDatabaseName());
        $this->assertSame(realpath($directorio), $directorio);
        $this->assertStringStartsWith(realpath(sys_get_temp_dir()).'/', $directorio);
        $this->assertSame($directorio.'/mysql.sock', $db->getConfig('unix_socket'));
        $servidor = DB::selectOne('SELECT DATABASE() AS nombre, @@datadir AS datos, @@socket AS socket,
            @@skip_networking AS privado, @@transaction_isolation AS aislamiento');
        $this->assertSame($db->getDatabaseName(), $servidor->nombre);
        $this->assertSame($directorio.'/data/', $servidor->datos);
        $this->assertSame($directorio.'/mysql.sock', $servidor->socket);
        $this->assertSame(1, (int) $servidor->privado);
        $this->assertSame('REPEATABLE-READ', $servidor->aislamiento);
    }

    #[DataProvider('carreras')]
    public function test_serializa_dos_solicitudes_del_titular(string $primera, string $segunda, ?string $error): void
    {
        $this->comprobarAislamiento();
        $cuentas = User::query()->count();
        $pacientes = Paciente::query()->count();
        $this->seed(CicloClinicoPermissionsSeeder::class);
        $medico = Medico::query()->habilitado()->orderBy('id')->firstOrFail();
        $actor = $medico->user;
        $actor->forceFill(['rol_id' => Rol::query()->where('nombre', 'Médico')->sole()->id])->save();
        $paciente = Paciente::query()->where('estado', 'ACTIVO')->orderBy('id')->firstOrFail();
        $eps = Eps::query()->where('estado', 'ACTIVA')->first() ?? Eps::factory()->create(['correo_electronico' => $actor->email]);
        $fecha = Carbon::parse($medico->disponibilidades()->max('fecha') ?? '2030-01-01', 'America/Bogota')->addDay()->setTime(8, 0);
        $this->travelTo($fecha->utc());
        $remision = RemisionEps::factory()->for($paciente)->for($eps)->create();
        $bloque = DisponibilidadMedica::factory()->for($medico)->create();
        $cita = Cita::factory()->for($paciente)->for($bloque, 'disponibilidad')->for($actor, 'creador')->create([
            'estado' => 'EN_ATENCION', 'modalidad' => 'EPS', 'remision_id' => $remision->id, 'valor_base' => null,
        ]);
        $atencion = AtencionMedica::factory()->for($cita)->create([
            'motivo_consulta' => 'Motivo previo', 'diagnostico_principal' => 'Diagnóstico previo',
        ])->refresh();
        $identidad = [$atencion->cita_id, $atencion->historia_clinica_id, $atencion->medico_id, $atencion->getRawOriginal('fecha_hora_inicio')];
        $horaCierre = now()->toDateTimeString();
        $respuesta = $this->competir(
            $actor, $this->solicitud($atencion, $primera, 'A'), $this->solicitud($atencion, $segunda, 'B'),
            $primera === 'finalizar' && $segunda === 'finalizar' ? 'remisiones_eps' : 'citas',
        );
        $this->assertSame($error === null ? 302 : 422, $respuesta['estado']);
        if ($error !== null) {
            $this->assertSame([$error], $respuesta['errores']);
        }
        $cerrada = $primera === 'finalizar';
        $atencion->refresh();
        $this->assertSame($cerrada ? 'FINALIZADA' : 'EN_CURSO', $atencion->estado);
        $this->assertSame($cerrada ? $horaCierre : null, $atencion->getRawOriginal('fecha_hora_fin'));
        $this->assertSame($cerrada ? 'Observación A' : null, $atencion->observacion_final);
        $this->assertSame($primera === 'motivo' ? 'Motivo A' : 'Motivo previo', $atencion->motivo_consulta);
        $diagnostico = $primera === 'diagnostico' ? 'Diagnóstico A' : 'Diagnóstico previo';
        if ($primera === 'motivo' && $segunda === 'diagnostico') {
            $diagnostico = 'Diagnóstico B';
        }
        $this->assertSame($diagnostico, $atencion->diagnostico_principal);
        $this->assertSame($identidad, [$atencion->cita_id, $atencion->historia_clinica_id, $atencion->medico_id, $atencion->getRawOriginal('fecha_hora_inicio')]);
        $this->assertSame(1, $cita->atencion()->count());
        $this->assertSame($cerrada ? 'ATENDIDA' : 'EN_ATENCION', $cita->refresh()->estado);
        $this->assertSame($remision->id, $cita->remision_reservada);
        $this->assertSame($cerrada ? 'USADA' : 'VIGENTE', $remision->refresh()->estado);
        $this->assertSame((int) $cerrada, $remision->cambiosEstado()->count());
        if ($cerrada) {
            $cambio = $remision->cambiosEstado()->sole();
            $this->assertSame(['VIGENTE', 'USADA', $actor->id], [$cambio->estado_anterior, $cambio->estado_nuevo, $cambio->user_id]);
        }
        $auditorias = Auditoria::query()->where('entidad', 'AtencionMedica')->where('registro_id', $atencion->id)->orderBy('id')->pluck('accion')->all();
        $esperadas = [match ($primera) {
            'finalizar' => 'ATENCION_FINALIZADA', 'motivo' => 'MOTIVO_CONSULTA_REGISTRADO', 'diagnostico' => 'DIAGNOSTICO_PRINCIPAL_REGISTRADO',
        }];
        if ($primera === 'motivo' && $segunda === 'diagnostico') {
            $esperadas[] = 'DIAGNOSTICO_PRINCIPAL_REGISTRADO';
        }
        $this->assertSame($esperadas, $auditorias);
        $this->assertSame($cuentas, User::query()->count());
        $this->assertSame($pacientes, Paciente::query()->count());
    }

    /** @return array{string, string, array<string,string>} */
    private function solicitud(AtencionMedica $atencion, string $accion, string $marca): array
    {
        $url = route('atenciones.clinica.'.$accion, $atencion);
        if ($accion === 'finalizar') {
            return ['POST', $url, ['observacion_final' => 'Observación '.$marca, 'confirmacion' => '1', 'version' => $atencion->versionClinica('cierre')]];
        }
        $esMotivo = $accion === 'motivo';
        $campo = $esMotivo ? 'motivo_consulta' : 'diagnostico_principal';

        return ['PATCH', $url, [$campo => ($esMotivo ? 'Motivo ' : 'Diagnóstico ').$marca, 'version' => $atencion->versionClinica($campo)]];
    }

    /**
     * @param  array{string, string, array<string,string>}  $primera
     * @param  array{string, string, array<string,string>}  $segunda
     * @return array{estado:int, errores:list<string>}
     */
    private function competir(User $actor, array $primera, array $segunda, string $tabla): array
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
                $this->travel(2)->minutes();
                $respuesta = $this->actingAs($actor)->json(...$segunda);
                fwrite($hijo, json_encode([
                    'estado' => $respuesta->getStatusCode(),
                    'errores' => $respuesta->getStatusCode() === 422 ? array_keys($respuesta->json('errors')) : [],
                ], JSON_THROW_ON_ERROR)."\n");
                exit(0);
            } catch (Throwable $error) {
                fwrite(STDERR, $error->getMessage().PHP_EOL);
                exit(1);
            }
        }

        fclose($hijo);
        stream_set_timeout($padre, 15);
        try {
            DB::beginTransaction();
            $this->actingAs($actor)->json(...$primera)->assertRedirect()->assertSessionHasNoErrors();
            fwrite($padre, "iniciar\n");
            $conexion = (int) fgets($padre);
            $this->assertGreaterThan(0, $conexion);
            $this->esperarBloqueo($conexion, $tabla);
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

    private function esperarBloqueo(int $conexion, string $tabla): void
    {
        $limite = microtime(true) + 5;
        do {
            $espera = DB::selectOne('SELECT recurso.OBJECT_NAME AS tabla
                FROM performance_schema.data_lock_waits espera
                JOIN performance_schema.threads hilo ON hilo.THREAD_ID = espera.REQUESTING_THREAD_ID
                JOIN performance_schema.data_locks recurso ON recurso.ENGINE_LOCK_ID = espera.REQUESTING_ENGINE_LOCK_ID
                WHERE hilo.PROCESSLIST_ID = ?', [$conexion]);
            if ($espera !== null) {
                $this->assertSame($tabla, $espera->tabla);

                return;
            }
            usleep(20000);
        } while (microtime(true) < $limite);

        $this->fail('La segunda solicitud no llegó a esperar el bloqueo de la primera.');
    }
}
