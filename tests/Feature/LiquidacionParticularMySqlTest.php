<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Liquidacion;
use App\Models\Rol;
use App\Models\User;
use App\Services\LiquidarConsultaParticular;
use Database\Seeders\CicloClinicoPermissionsSeeder;
use Database\Seeders\LiquidacionParticularPermissionsSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiquidacionParticularMySqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        $directorio = getenv('MEDISOFT_RF033_MYSQL_QA_DIR');
        if ($db->getDriverName() !== 'mysql' || ! $directorio) {
            $this->markTestSkipped('Requiere medisoft_pagos_correcciones_qa en una instancia MySQL privada.');
        }

        $this->assertTrue($this->app->environment('testing'));
        $this->assertFalse($this->app->configurationIsCached());
        $this->assertFalse((bool) $db->getConfig('url'));
        $this->assertSame('medisoft_pagos_correcciones_qa', $db->getDatabaseName());
        $this->assertSame(realpath($directorio), $directorio);
        $this->assertStringStartsWith(realpath(sys_get_temp_dir()).'/', $directorio);
        $this->assertSame($directorio.'/mysql.sock', $db->getConfig('unix_socket'));
        $identidad = DB::selectOne('SELECT DATABASE() AS esquema, @@socket AS socket, @@skip_networking AS privado,
            @@datadir AS datos, @@transaction_isolation AS aislamiento');
        $this->assertSame('medisoft_pagos_correcciones_qa', $identidad->esquema);
        $this->assertSame($directorio.'/mysql.sock', $identidad->socket);
        $this->assertSame($directorio.'/data/', $identidad->datos);
        $this->assertSame(1, (int) $identidad->privado);
        $this->assertSame('REPEATABLE-READ', $identidad->aislamiento);
    }

    public static function carreras(): array
    {
        return [
            'doble liquidación' => ['liquidar', 'liquidar', true, 302, 302],
            'RF-031 toma el bloqueo primero' => ['cerrar', 'liquidar', false, 302, 302],
            'RF-033 toma el bloqueo primero' => ['liquidar', 'cerrar', false, 422, 302],
        ];
    }

    #[DataProvider('carreras')]
    public function test_serializa_solicitudes_sobre_la_cita(string $primera, string $segunda, bool $finalizada, int $estadoPrimera, int $estadoSegunda): void
    {
        $this->assertTrue(function_exists('pcntl_fork'));
        [$actor, $atencion] = $this->escenario($finalizada);
        $auditoriasPrevias = Auditoria::query()->where('entidad', 'Liquidacion')->count();
        $versionImporte = app(LiquidarConsultaParticular::class)->versionImporte($actor, $atencion, '80000.00');
        $solicitudes = [
            'liquidar' => [$this->url($atencion), ['confirmacion' => '1', 'version_importe' => $versionImporte]],
            'cerrar' => [route('atenciones.clinica.finalizar', $atencion), ['confirmacion' => '1', 'version' => $atencion->versionClinica('cierre')]],
        ];

        DB::disconnect();
        $canales = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($canales);
        [$padre, $hijo] = $canales;
        $pid = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($padre);
            stream_set_timeout($hijo, 15);
            if (fgets($hijo) !== "iniciar\n") {
                exit(1);
            }
            try {
                fwrite($hijo, DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n");
                $respuesta = $this->actingAs($actor)->postJson(...$solicitudes[$segunda]);
                fwrite($hijo, $respuesta->getStatusCode()."\n");
                exit(0);
            } catch (\Throwable $error) {
                fwrite(STDERR, $error::class.PHP_EOL);
                exit(1);
            }
        }

        fclose($hijo);
        stream_set_timeout($padre, 15);
        try {
            DB::beginTransaction();
            $respuesta = $this->actingAs($actor)->postJson(...$solicitudes[$primera])->assertStatus($estadoPrimera);
            if ($estadoPrimera === 422) {
                $respuesta->assertJsonValidationErrors('atencion');
                $this->assertFalse($this->liquidaciones($atencion)->exists());
            }
            fwrite($padre, "iniciar\n");
            $conexion = (int) fgets($padre);
            $this->assertGreaterThan(0, $conexion);
            $this->esperarBloqueoDeCita($conexion);
            DB::commit();
            $this->assertSame($estadoSegunda, (int) fgets($padre));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($padre);
            pcntl_waitpid($pid, $estado);
        }
        $this->assertTrue(pcntl_wifexited($estado));
        $this->assertSame(0, pcntl_wexitstatus($estado));

        $this->assertSame('FINALIZADA', $atencion->fresh()->estado);
        $this->assertSame('ATENDIDA', $atencion->cita()->value('estado'));
        $this->assertSame($finalizada ? 0 : 1, Auditoria::query()->where('entidad', 'AtencionMedica')
            ->where('registro_id', $atencion->id)->where('accion', 'ATENCION_FINALIZADA')->count());

        if ($estadoPrimera === 422) {
            $this->assertFalse($this->liquidaciones($atencion)->exists());
            $this->assertSame($auditoriasPrevias, Auditoria::query()->where('entidad', 'Liquidacion')->count());

            return;
        }
        $liquidacion = $this->liquidaciones($atencion)->sole();
        $this->assertSame('80000.00', $liquidacion->valor_total);
        $this->assertSame(1, Auditoria::query()->where('entidad', 'Liquidacion')->where('registro_id', $liquidacion->id)
            ->where('accion', 'LIQUIDACION_PARTICULAR_REGISTRADA')->count());
    }

    public function test_indice_unico_es_global_por_atencion_y_la_base_restringe_importes(): void
    {
        [$actor, $atencion] = $this->escenario();
        $fila = [
            'atencion_medica_id' => $atencion->id,
            'modalidad' => 'PARTICULAR',
            'valor_total' => '80000.00',
            'fecha_liquidacion' => now(),
            'creado_por' => $actor->id,
        ];
        foreach ([['PARTICULAR', '0.00'], ['EPS', '-0.01'], ['OTRA', '1.00'], ['particular', '1.00']] as [$modalidad, $valor]) {
            try {
                DB::table('liquidaciones')->insert(array_replace($fila, ['modalidad' => $modalidad, 'valor_total' => $valor]));
                $this->fail('MySQL admitió una modalidad o importe inválido.');
            } catch (QueryException $error) {
                $this->assertSame(3819, (int) $error->errorInfo[1]);
            }
        }
        DB::beginTransaction();
        try {
            DB::table('liquidaciones')->insert(array_replace($fila, ['modalidad' => 'EPS', 'valor_total' => '0.00']));
            try {
                DB::table('liquidaciones')->insert($fila);
                $this->fail('MySQL admitió dos liquidaciones para la misma atención.');
            } catch (QueryException $error) {
                $this->assertSame(1062, (int) $error->errorInfo[1]);
            }
            $this->assertSame('EPS', $this->liquidaciones($atencion)->sole()->modalidad);
        } finally {
            DB::rollBack();
        }
    }

    public function test_mysql_revierte_si_falla_la_auditoria(): void
    {
        [$actor, $atencion] = $this->escenario();
        $datos = $this->confirmacion($actor, $atencion);
        $auditorias = Auditoria::query()->count();
        Auditoria::creating(fn () => DB::select('SELECT contenido FROM tabla_auditoria_no_disponible'));
        $this->postJson($this->url($atencion), $datos)->assertServiceUnavailable()->assertDontSee('tabla_auditoria_no_disponible');
        $this->assertFalse($this->liquidaciones($atencion)->exists());
        $this->assertSame($auditorias, Auditoria::query()->count());
        $this->assertSame('FINALIZADA', $atencion->fresh()->estado);
        $this->assertSame('ATENDIDA', $atencion->cita()->value('estado'));
    }

    public function test_mysql_conserva_importe_y_fecha_tras_variar_el_valor_base(): void
    {
        [$actor, $atencion] = $this->escenario();
        $datos = $this->confirmacion($actor, $atencion);
        $this->postJson($this->url($atencion), $datos)->assertRedirect();
        $original = $this->liquidaciones($atencion)->sole();
        $atencion->cita()->update(['valor_base' => '1.01']);
        $this->travel(10)->minutes();
        $this->postJson($this->url($atencion), $datos)->assertRedirect();
        $this->get($this->url($atencion))->assertOk()->assertSee('80000.00 COP')->assertDontSee('1.01 COP');
        $this->assertSame($original->getAttributes(), $this->liquidaciones($atencion)->sole()->getAttributes());
        $this->assertSame(1, Auditoria::query()->where('entidad', 'Liquidacion')->where('registro_id', $original->id)->count());
    }

    /** @return array{User, AtencionMedica} */
    private function escenario(bool $finalizada = true): array
    {
        $this->seed([SecuritySeeder::class, CicloClinicoPermissionsSeeder::class, LiquidacionParticularPermissionsSeeder::class]);
        $actor = User::factory()->for(Rol::query()->where('nombre', 'Médico')->sole())->create();
        $medico = $actor->medico()->create(['registro_profesional' => fake()->unique()->numerify('QA-RF033-########'), 'especialidad' => 'QA']);
        $bloque = DisponibilidadMedica::factory()->for($medico)->create();
        $cita = Cita::factory()->for($bloque, 'disponibilidad')->for($actor, 'creador')->create([
            'estado' => $finalizada ? 'ATENDIDA' : 'EN_ATENCION',
            'valor_base' => '80000.00',
        ]);
        $atencion = AtencionMedica::factory()->for($cita)->create([
            'estado' => $finalizada ? 'FINALIZADA' : 'EN_CURSO',
            'fecha_hora_fin' => $finalizada ? now() : null,
            'motivo_consulta' => 'Motivo ficticio',
            'diagnostico_principal' => 'Diagnóstico ficticio',
        ])->refresh();

        return [$actor, $atencion];
    }

    private function confirmacion(User $actor, AtencionMedica $atencion): array
    {
        $respuesta = $this->actingAs($actor)->get($this->url($atencion))->assertOk();

        return ['confirmacion' => '1', 'version_importe' => $respuesta->viewData('version')];
    }

    private function url(AtencionMedica $atencion): string
    {
        return route('pagos-particulares.liquidaciones.show', $atencion->id);
    }

    private function liquidaciones(AtencionMedica $atencion): Builder
    {
        return Liquidacion::query()->where('atencion_medica_id', $atencion->id);
    }

    private function esperarBloqueoDeCita(int $conexion): void
    {
        $limite = microtime(true) + 5;
        do {
            $espera = DB::selectOne('SELECT recurso.OBJECT_NAME AS tabla
                FROM performance_schema.data_lock_waits espera
                JOIN performance_schema.threads hilo ON hilo.THREAD_ID = espera.REQUESTING_THREAD_ID
                JOIN performance_schema.data_locks recurso ON recurso.ENGINE_LOCK_ID = espera.REQUESTING_ENGINE_LOCK_ID
                WHERE hilo.PROCESSLIST_ID = ?', [$conexion]);
            if ($espera !== null) {
                $this->assertSame('citas', $espera->tabla);

                return;
            }
            usleep(20000);
        } while (microtime(true) < $limite);

        $this->fail('La segunda operación no esperó el bloqueo de la cita.');
    }
}
