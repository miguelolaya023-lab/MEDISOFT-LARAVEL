<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\Liquidacion;
use App\Models\PagoParticular;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class PagoParticularMySqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        $directorio = getenv('MEDISOFT_RF034_MYSQL_QA_DIR');
        if ($db->getDriverName() !== 'mysql' || ! $directorio) {
            $this->markTestSkipped('Requiere el esquema ficticio medisoft_pagos_correcciones_qa en una instancia MySQL privada.');
        }

        $this->assertTrue($this->app->environment('testing'));
        $this->assertFalse($this->app->configurationIsCached());
        $this->assertEmpty($db->getConfig('url'));
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
            'misma clave y mismos datos' => [true, false, 302],
            'claves distintas' => [false, true, 302],
            'misma clave y distintos datos' => [true, true, 422],
        ];
    }

    #[DataProvider('carreras')]
    public function test_pagos_simultaneos_bloquean_la_misma_operacion(bool $mismaClave, bool $cambiaMetodo, int $estadoHijo): void
    {
        $this->assertTrue(function_exists('pcntl_fork'));
        [$actor, $liquidacion] = $this->escenario();
        $this->actingAs($actor);
        $datos = $this->datos($liquidacion);
        $otros = array_replace($mismaClave ? $datos : $this->datos($liquidacion), [
            'metodo_pago' => $cambiaMetodo ? 'TARJETA' : $datos['metodo_pago'],
        ]);
        $url = $this->url($liquidacion);

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
                $respuesta = $this->actingAs($actor)->postJson($url, $otros);
                fwrite($hijo, json_encode([
                    'estado' => $respuesta->getStatusCode(), 'destino' => $respuesta->headers->get('Location'),
                    'errores' => $respuesta->getStatusCode() === 422 ? array_keys($respuesta->json('errors')) : [],
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
            $primero = $this->actingAs($actor)->postJson($url, $datos)->assertRedirect();
            fwrite($padre, "iniciar\n");
            $conexion = (int) fgets($padre);
            $this->assertGreaterThan(0, $conexion);
            $this->esperarBloqueo($conexion);
            DB::commit();
            $salida = fgets($padre);
            $this->assertNotFalse($salida);
            $resultado = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($estadoHijo, $resultado['estado']);
            if ($estadoHijo === 302) {
                $this->assertSame($primero->headers->get('Location'), $resultado['destino']);
            } else {
                $this->assertSame(['solicitud'], $resultado['errores']);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($padre);
            pcntl_waitpid($pid, $estado);
        }
        $this->assertTrue(pcntl_wifexited($estado));
        $this->assertSame(0, pcntl_wexitstatus($estado));
        $pago = $liquidacion->pagosParticulares()->sole();
        $this->assertSame(['80000.00', 'EFECTIVO', 'REGISTRADO'], [$pago->valor_pagado, $pago->metodo_pago, $pago->estado]);
        $this->assertSame($pago->id, $liquidacion->pagoVigente()->sole()->id);
        $this->assertSame(1, $this->auditorias($pago));
        $this->assertSame($liquidacion->getAttributes(), $liquidacion->fresh()->getAttributes());
    }

    public function test_mysql_unicidad_recibo_clave_y_pago_vigente_y_conservacion_de_anulados(): void
    {
        [$actor, $liquidacion] = $this->escenario();
        [, $otra] = $this->escenario();
        DB::beginTransaction();
        try {
            $vigente = PagoParticular::factory()->for($liquidacion)->create(['creado_por' => $actor->id]);
            PagoParticular::factory()->for($liquidacion)->count(2)->create(['estado' => 'ANULADO', 'creado_por' => $actor->id]);
            $this->assertSame(3, $liquidacion->pagosParticulares()->count());
            $this->assertSame($vigente->id, $liquidacion->pagoVigente()->sole()->id);
            foreach ([
                [$liquidacion, [], 1062],
                [$otra, ['estado' => 'ANULADO', 'numero_recibo' => $vigente->numero_recibo], 1062],
                [$liquidacion, ['estado' => 'ANULADO', 'clave_envio' => $vigente->clave_envio], 1062],
                [$liquidacion, ['estado' => 'OTRO'], 3819],
                [$liquidacion, ['estado' => 'ANULADO', 'metodo_pago' => 'efectivo'], 3819],
                [$liquidacion, ['estado' => 'ANULADO', 'valor_pagado' => '0.00'], 3819],
            ] as [$destino, $datos, $codigo]) {
                try {
                    PagoParticular::factory()->for($destino)->create($datos + ['creado_por' => $actor->id]);
                    $this->fail('MySQL admitió una fila fuera del contrato.');
                } catch (QueryException $error) {
                    $this->assertSame($codigo, (int) $error->errorInfo[1]);
                }
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_mysql_reemplaza_anulado_sin_reutilizar_recibo_ni_importe_de_cita(): void
    {
        [$actor, $liquidacion] = $this->escenario();
        $this->actingAs($actor);
        $anterior = $this->datos($liquidacion);
        $obsoleta = $this->datos($liquidacion);
        $auditorias = Auditoria::query()->where('entidad', 'PagoParticular')->count();
        $primero = $this->postJson($this->url($liquidacion), $anterior)->assertRedirect();
        $historico = $liquidacion->pagosParticulares()->sole();
        $this->postJson($this->url($liquidacion), $obsoleta)->assertRedirect($primero->headers->get('Location'));
        $this->assertSame(1, $liquidacion->pagosParticulares()->count());
        $this->assertFalse($liquidacion->pagosParticulares()->where('clave_envio', $obsoleta['clave_envio'])->exists());
        DB::table('pagos_particulares')->where('id', $historico->id)->update(['estado' => 'ANULADO']);
        $this->postJson($this->url($liquidacion), $obsoleta)->assertUnprocessable()->assertJsonValidationErrors('intencion');
        $this->assertSame(1, $liquidacion->pagosParticulares()->count());
        $this->assertSame($auditorias + 1, Auditoria::query()->where('entidad', 'PagoParticular')->count());
        $liquidacion->atencion->cita()->update(['valor_base' => '60023.45']);
        $this->assertSame('PENDIENTE_PAGO', $liquidacion->estadoParticular());

        $this->postJson($this->url($liquidacion), $this->datos($liquidacion))->assertRedirect();
        $reemplazo = $liquidacion->pagoVigente()->sole();
        $this->assertNotSame($historico->numero_recibo, $reemplazo->numero_recibo);
        $this->assertSame('ANULADO', $historico->fresh()->estado);
        $this->assertSame('80000.00', $reemplazo->valor_pagado);
        $this->assertSame($liquidacion->getAttributes(), $liquidacion->fresh()->getAttributes());
        $this->assertSame('PAGADA', $liquidacion->estadoParticular());
        $this->postJson($this->url($liquidacion), $anterior)
            ->assertRedirect(route('pagos-particulares.pagos.resultado', [$liquidacion, $historico]));
        $this->assertSame(2, $liquidacion->pagosParticulares()->count());
        $this->assertSame(1, $this->auditorias($historico));
        $this->assertSame(1, $this->auditorias($reemplazo));
        DB::table('pagos_particulares')->where('id', $reemplazo->id)->update(['estado' => 'ANULADO']);
        $this->postJson($this->url($liquidacion), $obsoleta)->assertUnprocessable()->assertJsonValidationErrors('intencion');
        $this->postJson($this->url($liquidacion), $this->datos($liquidacion))->assertRedirect();
        $tercero = $liquidacion->pagoVigente()->sole();
        $this->assertNotContains($tercero->numero_recibo, [$historico->numero_recibo, $reemplazo->numero_recibo]);
        $this->assertSame(2, $liquidacion->pagosParticulares()->where('estado', 'ANULADO')->count());
        $this->assertSame($auditorias + 3, Auditoria::query()->where('entidad', 'PagoParticular')->count());
    }

    public function test_mysql_rechaza_firma_manipulada_y_formulario_sin_firma_sin_escrituras(): void
    {
        [$actor, $liquidacion] = $this->escenario();
        $this->actingAs($actor);
        $datos = $this->datos($liquidacion);
        $total = Auditoria::query()->where('entidad', 'PagoParticular')->count();
        $this->postJson($this->url($liquidacion), array_replace($datos, ['intencion' => '0-SIN_PAGO.'.str_repeat('0', 64)]))
            ->assertUnprocessable()->assertJsonValidationErrors('intencion');
        $this->postJson($this->url($liquidacion), array_diff_key($datos, ['intencion' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('intencion');
        $this->assertFalse($liquidacion->pagosParticulares()->exists());
        $this->assertSame($total, Auditoria::query()->where('entidad', 'PagoParticular')->count());
    }

    public function test_mysql_revierte_fallo_de_auditoria_y_libera_la_misma_clave(): void
    {
        [$actor, $liquidacion] = $this->escenario();
        $this->actingAs($actor);
        $datos = $this->datos($liquidacion);
        $total = Auditoria::query()->where('entidad', 'PagoParticular')->count();
        Auditoria::creating(fn () => DB::select('SELECT contenido FROM tabla_auditoria_no_disponible'));
        try {
            $this->actingAs($actor)->postJson($this->url($liquidacion), $datos)
                ->assertServiceUnavailable()->assertDontSee('tabla_auditoria_no_disponible');
            $this->assertFalse($liquidacion->pagosParticulares()->exists());
            $this->assertSame($total, Auditoria::query()->where('entidad', 'PagoParticular')->count());
            $this->assertSame('PENDIENTE_PAGO', $liquidacion->estadoParticular());
        } finally {
            Auditoria::flushEventListeners();
        }
        $this->postJson($this->url($liquidacion), $datos)->assertRedirect();
        $this->assertSame(1, $this->auditorias($liquidacion->pagoVigente()->sole()));
    }

    /** @return array{User, Liquidacion} */
    private function escenario(): array
    {
        $actor = User::factory()->for(Rol::query()->where('nombre', 'Administrativo')->sole())->create();
        $this->assertTrue($actor->tienePermiso('RF-034'));
        $atencion = AtencionMedica::factory()->for(Cita::factory()->state(['estado' => 'ATENDIDA']))->create([
            'estado' => 'FINALIZADA', 'fecha_hora_fin' => now(),
        ]);
        $liquidacion = Liquidacion::query()->create([
            'atencion_medica_id' => $atencion->id, 'modalidad' => 'PARTICULAR', 'valor_total' => '80000.00',
            'fecha_liquidacion' => now(), 'creado_por' => $actor->id,
        ]);

        return [$actor, $liquidacion->refresh()];
    }

    private function datos(Liquidacion $liquidacion): array
    {
        $formulario = $this->get(route('pagos-particulares.pagos.show', $liquidacion))->assertOk();

        return [
            'clave_envio' => $formulario->viewData('clave'), 'intencion' => $formulario->viewData('intencion'),
            'confirmacion' => '1', 'valor_pagado' => '80000.00',
            'fecha_pago' => now('America/Bogota')->toDateString(), 'metodo_pago' => 'EFECTIVO',
        ];
    }

    private function url(Liquidacion $liquidacion): string
    {
        return route('pagos-particulares.pagos.store', $liquidacion);
    }

    private function auditorias(PagoParticular $pago): int
    {
        return Auditoria::query()->where('entidad', 'PagoParticular')->where('registro_id', $pago->id)
            ->where('accion', 'PAGO_PARTICULAR_REGISTRADO')->count();
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
                $this->assertSame('citas', $espera->tabla);

                return;
            }
            usleep(20000);
        } while (microtime(true) < $limite);

        $this->fail('La segunda solicitud no esperó el bloqueo de la primera.');
    }
}
