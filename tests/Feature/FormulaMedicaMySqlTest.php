<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\DetalleFormula;
use App\Models\DisponibilidadMedica;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class FormulaMedicaMySqlTest extends TestCase
{
    /** @return array<string, array{string, string, ?string}> */
    public static function carreras(): array
    {
        return [
            'cierre antes del alta de fórmula' => ['finalizar', 'registrar', 'atencion'],
            'alta de fórmula antes del cierre' => ['registrar', 'finalizar', null],
            'dos altas con la misma clave de envío' => ['registrar', 'registrar', null],
        ];
    }

    #[DataProvider('carreras')]
    public function test_serializa_el_alta_de_formula_y_el_cierre_clinico(string $primera, string $segunda, ?string $error): void
    {
        $this->comprobarAislamiento();
        $rol = Rol::query()->firstOrCreate(['nombre' => 'Médico']);
        foreach (['RF-057', 'RF-031'] as $codigo) {
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

        $detallesAntes = DetalleFormula::query()->count();
        $clave = (string) Str::uuid();
        $respuesta = $this->competir(
            $actor,
            $this->solicitud($atencion, $primera, $clave),
            $this->solicitud($atencion, $segunda, $clave),
        );
        $this->assertSame($error === null ? 302 : 422, $respuesta['estado']);
        $this->assertSame($error === null ? [] : [$error], $respuesta['errores']);

        $cerrada = in_array('finalizar', [$primera, $segunda], true);
        $this->assertSame($cerrada ? 'FINALIZADA' : 'EN_CURSO', $atencion->refresh()->estado);
        $this->assertSame($cerrada ? 'ATENDIDA' : 'EN_ATENCION', $cita->refresh()->estado);
        $this->assertSame($cerrada, $atencion->fecha_hora_fin !== null);

        $auditorias = Auditoria::query()->where('user_id', $actor->id);
        $auditoriasFormula = (clone $auditorias)->where('entidad', 'FormulaMedica')->where('accion', 'FORMULA_MEDICA_REGISTRADA');
        $this->assertSame((int) $cerrada, $auditorias->where('entidad', 'AtencionMedica')
            ->where('accion', 'ATENCION_FINALIZADA')->where('registro_id', $atencion->id)->count());

        if ($primera === 'finalizar') {
            $this->assertSame(0, $atencion->formulasMedicas()->count());
            $this->assertSame($detallesAntes, DetalleFormula::query()->count());
            $this->assertSame(0, $auditoriasFormula->count());

            return;
        }

        $formula = $atencion->formulasMedicas()->sole();
        $this->assertSame($clave, $formula->clave_envio);
        $this->assertSame($detallesAntes + 2, DetalleFormula::query()->count());
        $detalles = $formula->detalles()->orderBy('posicion')->get();
        $this->assertSame(['Medicamento ficticio A', 'Medicamento ficticio B'], $detalles->pluck('medicamento')->all());
        $this->assertSame(['Dosis ficticia A', 'Dosis ficticia B'], $detalles->pluck('dosis')->all());
        $this->assertEqualsCanonicalizing([$formula->id], $auditoriasFormula->pluck('registro_id')->all());
    }

    /** @return array{string, string, array<string, mixed>} */
    private function solicitud(AtencionMedica $atencion, string $accion, string $clave): array
    {
        if ($accion === 'finalizar') {
            return ['POST', route('atenciones.clinica.finalizar', $atencion), [
                'confirmacion' => '1', 'version' => $atencion->versionClinica('cierre'),
            ]];
        }

        return ['POST', route('atenciones.clinica.formulas.store', $atencion), [
            'clave_envio' => $clave,
            'detalles_formula' => [
                ['medicamento' => 'Medicamento ficticio A', 'dosis' => 'Dosis ficticia A'],
                ['medicamento' => 'Medicamento ficticio B', 'dosis' => 'Dosis ficticia B'],
            ],
        ]];
    }

    private function comprobarAislamiento(): void
    {
        $db = DB::connection();
        $directorio = getenv('MEDISOFT_RF057_MYSQL_QA_DIR');
        if ($db->getDriverName() !== 'mysql' || ! $directorio) {
            $this->markTestSkipped('Requiere MySQL QA desechable y MEDISOFT_RF057_MYSQL_QA_DIR.');
        }
        $this->assertTrue($this->app->environment('testing'));
        $this->assertFalse($this->app->configurationIsCached());
        $this->assertTrue(function_exists('pcntl_fork'));
        $this->assertEmpty($db->getConfig('url'));
        $this->assertSame('medisoft_rf057_qa', $db->getDatabaseName());
        $this->assertSame(realpath($directorio), $directorio);
        $this->assertSame(realpath(sys_get_temp_dir()), dirname($directorio));
        $this->assertStringStartsWith('medisoft-rf057-mysql.', basename($directorio));
        $this->assertSame($directorio.'/mysql.sock', $db->getConfig('unix_socket'));
        $servidor = DB::selectOne('SELECT DATABASE() AS base, @@datadir AS datos, @@socket AS socket,
            @@skip_networking AS sin_red, @@transaction_isolation AS aislamiento');
        $this->assertSame('medisoft_rf057_qa', $servidor->base);
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
