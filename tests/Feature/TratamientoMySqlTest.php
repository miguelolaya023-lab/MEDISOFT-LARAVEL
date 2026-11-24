<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Permiso;
use App\Models\RevisionTratamiento;
use App\Models\Rol;
use App\Models\Tratamiento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class TratamientoMySqlTest extends TestCase
{
    /** @return array<string, array{string, string, ?string}> */
    public static function carreras(): array
    {
        return [
            'cierre antes de registrar tratamiento' => ['finalizar', 'registrar', 'atencion'],
            'cierre antes de corregir tratamiento' => ['finalizar', 'corregir', 'atencion'],
            'registro antes del cierre' => ['registrar', 'finalizar', null],
            'corrección antes del cierre' => ['corregir', 'finalizar', null],
            'dos altas con la misma clave de envío' => ['registrar', 'registrar', null],
            'dos correcciones con la misma versión' => ['corregir', 'corregir', 'version'],
        ];
    }

    #[DataProvider('carreras')]
    public function test_serializa_el_tratamiento_con_el_cierre_clinico(string $primera, string $segunda, ?string $error): void
    {
        $this->comprobarAislamiento();
        $rol = Rol::query()->firstOrCreate(['nombre' => 'Médico']);
        foreach (['RF-056', 'RF-031'] as $codigo) {
            $permiso = Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $codigo]);
            $rol->permisos()->syncWithoutDetaching([$permiso->id]);
        }
        $actor = User::factory()->for($rol)->create();
        $medico = $actor->medico()->create([
            'registro_profesional' => fake()->unique()->numerify('DEMO-########'),
            'especialidad' => 'Medicina general',
        ]);
        $bloque = DisponibilidadMedica::factory()->for($medico)->create();
        $cita = Cita::factory()->for($bloque, 'disponibilidad')->for($actor, 'creador')->create(['estado' => 'EN_ATENCION']);
        $atencion = AtencionMedica::factory()->for($cita)->create([
            'motivo_consulta' => 'Motivo ficticio QA',
            'diagnostico_principal' => 'Diagnóstico ficticio QA',
        ])->refresh();
        $tratamiento = null;
        if (in_array('corregir', [$primera, $segunda], true)) {
            $this->actingAs($actor)->json(...$this->solicitud($atencion, 'registrar', 'Tratamiento inicial QA', null, (string) Str::uuid()))
                ->assertRedirect()->assertSessionHasNoErrors();
            $tratamiento = Tratamiento::query()->where('atencion_medica_id', $atencion->id)->sole();
        }

        $clave = (string) Str::uuid();
        $respuesta = $this->competir(
            $actor,
            $this->solicitud($atencion, $primera, 'Tratamiento A QA', $tratamiento, $clave),
            $this->solicitud($atencion, $segunda, $segunda === 'registrar' ? 'Tratamiento A QA' : 'Tratamiento B QA', $tratamiento, $clave),
        );
        $this->assertSame($error === null ? 302 : 422, $respuesta['estado']);
        $this->assertSame($error === null ? [] : [$error], $respuesta['errores']);

        $cerrada = in_array('finalizar', [$primera, $segunda], true);
        $this->assertSame($cerrada ? 'FINALIZADA' : 'EN_CURSO', $atencion->refresh()->estado);
        $this->assertSame($cerrada ? 'ATENDIDA' : 'EN_ATENCION', $cita->refresh()->estado);
        $this->assertSame($cerrada, $atencion->fecha_hora_fin !== null);

        $guardado = Tratamiento::query()->where('atencion_medica_id', $atencion->id);
        if ($primera === 'finalizar' && $segunda === 'registrar') {
            $this->assertSame(0, $guardado->count());
        } else {
            $actual = $guardado->sole();
            if ($tratamiento !== null) {
                $this->assertSame($tratamiento->id, $actual->id);
            }
            $this->assertSame($primera === 'finalizar' ? 'Tratamiento inicial QA' : 'Tratamiento A QA', $actual->descripcion);
            $revisiones = RevisionTratamiento::query()->where('tratamiento_id', $actual->id);
            if ($primera === 'corregir') {
                $revision = $revisiones->sole();
                $this->assertSame('Tratamiento inicial QA', $revision->descripcion_anterior);
                $this->assertSame($actor->id, $revision->user_id);
            } else {
                $this->assertSame(0, $revisiones->count());
            }
        }

        $auditorias = Auditoria::query()->where('user_id', $actor->id);
        $this->assertSame((int) ($tratamiento !== null || $primera === 'registrar'), (clone $auditorias)->where('accion', 'TRATAMIENTO_REGISTRADO')->count());
        $this->assertSame((int) ($primera === 'corregir'), (clone $auditorias)->where('accion', 'TRATAMIENTO_CORREGIDO')->count());
        $this->assertSame((int) $cerrada, $auditorias->where('accion', 'ATENCION_FINALIZADA')->count());
    }

    /** @return array{string, string, array<string,string>} */
    private function solicitud(AtencionMedica $atencion, string $accion, string $texto, ?Tratamiento $tratamiento, string $clave): array
    {
        return match ($accion) {
            'finalizar' => ['POST', route('atenciones.clinica.finalizar', $atencion), [
                'confirmacion' => '1', 'version' => $atencion->versionClinica('cierre'),
            ]],
            'registrar' => ['POST', route('atenciones.clinica.tratamientos.store', $atencion), [
                'descripcion_tratamiento' => $texto, 'clave_envio' => $clave,
            ]],
            'corregir' => ['PATCH', route('atenciones.clinica.tratamientos.update', [$atencion, $tratamiento]), [
                'descripcion_tratamiento' => $texto, 'version' => $tratamiento->versionClinica(),
            ]],
        };
    }

    private function comprobarAislamiento(): void
    {
        $db = DB::connection();
        $directorio = getenv('MEDISOFT_RF056_MYSQL_QA_DIR');
        if ($db->getDriverName() !== 'mysql' || ! $directorio) {
            $this->markTestSkipped('Requiere MySQL QA desechable y MEDISOFT_RF056_MYSQL_QA_DIR.');
        }
        $this->assertTrue($this->app->environment('testing'));
        $this->assertFalse($this->app->configurationIsCached());
        $this->assertTrue(function_exists('pcntl_fork'));
        $this->assertEmpty($db->getConfig('url'));
        $this->assertSame('medisoft_rf056_qa', $db->getDatabaseName());
        $this->assertSame(realpath($directorio), $directorio);
        $this->assertSame(realpath(sys_get_temp_dir()), dirname($directorio));
        $this->assertStringStartsWith('medisoft-rf056-mysql.', basename($directorio));
        $this->assertSame($directorio.'/mysql.sock', $db->getConfig('unix_socket'));
        $servidor = DB::selectOne('SELECT DATABASE() AS base, @@datadir AS datos, @@socket AS socket,
            @@skip_networking AS sin_red, @@transaction_isolation AS aislamiento');
        $this->assertSame('medisoft_rf056_qa', $servidor->base);
        $this->assertSame($directorio.'/data/', $servidor->datos);
        $this->assertSame($directorio.'/mysql.sock', $servidor->socket);
        $this->assertSame(1, (int) $servidor->sin_red);
        $this->assertSame('REPEATABLE-READ', $servidor->aislamiento);
    }

    /**
     * @param  array{string, string, array<string,string>}  $primera
     * @param  array{string, string, array<string,string>}  $segunda
     * @return array{estado:int, errores:list<string>}
     */
    private function competir(User $actor, array $primera, array $segunda): array
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
                $respuesta = $this->actingAs($actor)->json(...$segunda);
                fwrite($hijo, json_encode([
                    'estado' => $respuesta->status(),
                    'errores' => $respuesta->status() === 422 ? array_keys($respuesta->json('errors')) : [],
                ], JSON_THROW_ON_ERROR)."\n");
                exit(0);
            } catch (Throwable $error) {
                fwrite(STDERR, $error::class.PHP_EOL);
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
            $espera = DB::selectOne('SELECT recurso.OBJECT_NAME AS tabla
                FROM performance_schema.data_lock_waits espera
                JOIN performance_schema.threads hilo ON hilo.THREAD_ID = espera.REQUESTING_THREAD_ID
                JOIN performance_schema.data_locks recurso ON recurso.ENGINE_LOCK_ID = espera.REQUESTING_ENGINE_LOCK_ID
                WHERE hilo.PROCESSLIST_ID = ?', [$conexion]);
            if ($espera !== null) {
                $this->assertContains($espera->tabla, ['citas', 'atenciones_medicas']);

                return;
            }
            usleep(20000);
        } while (microtime(true) < $limite);

        $this->fail('La segunda solicitud no esperó un bloqueo clínico de la primera.');
    }
}
