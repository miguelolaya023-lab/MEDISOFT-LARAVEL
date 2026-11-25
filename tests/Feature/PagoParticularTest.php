<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\Liquidacion;
use App\Models\Paciente;
use App\Models\PagoParticular;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\PagoParticularPermissionsSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PagoParticularTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $db = DB::connection();
        if (! $this->app->environment('testing') || $this->app->configurationIsCached()
            || $db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:' || $db->getConfig('url')) {
            throw new LogicException('La prueba requiere SQLite en memoria.');
        }
    }

    private function administrativo(): User
    {
        $this->seed([SecuritySeeder::class, PagoParticularPermissionsSeeder::class]);

        return User::factory()->for(Rol::query()->where('nombre', 'Administrativo')->sole())->create();
    }

    private function liquidacion(User $actor, array $cita = [], array $atencion = [], string $modalidad = 'PARTICULAR', string $total = '80000.00'): Liquidacion
    {
        $actual = AtencionMedica::factory()->for(Cita::factory()->state($cita + ['estado' => 'ATENDIDA']))
            ->create($atencion + ['estado' => 'FINALIZADA', 'fecha_hora_fin' => now(), 'diagnostico_principal' => 'DIAGNOSTICO-QA-RESERVADO']);

        return Liquidacion::query()->create([
            'atencion_medica_id' => $actual->id, 'modalidad' => $modalidad,
            'valor_total' => $total, 'fecha_liquidacion' => now(), 'creado_por' => $actor->id,
        ])->refresh();
    }

    private function datos(array $extra = []): array
    {
        return $extra + [
            'clave_envio' => (string) Str::uuid(), 'confirmacion' => '1', 'valor_pagado' => '80000.00',
            'fecha_pago' => now('America/Bogota')->toDateString(), 'metodo_pago' => 'EFECTIVO',
        ];
    }

    private function confirmacion(Liquidacion $liquidacion, array $extra = []): array
    {
        $formulario = $this->get($this->url($liquidacion))->assertOk();

        return $this->datos($extra + [
            'clave_envio' => $formulario->viewData('clave'),
            'intencion' => $formulario->viewData('intencion'),
        ]);
    }

    private function url(?Liquidacion $liquidacion = null): string
    {
        return $liquidacion === null ? route('pagos-particulares.pagos.index') : route('pagos-particulares.pagos.show', $liquidacion);
    }

    private function resultado(Liquidacion $liquidacion, PagoParticular $pago): string
    {
        return route('pagos-particulares.pagos.resultado', [$liquidacion, $pago]);
    }

    public function test_ca03401_pago_completo_con_recibo_y_estado_derivado_sin_permiso_rf033(): void
    {
        $actor = $this->administrativo();
        $this->assertFalse($actor->medico()->exists());
        $this->assertFalse($actor->tienePermiso('RF-033'));
        $liquidacion = $this->liquidacion($actor, ['valor_base' => '12345.67']);
        $this->actingAs($actor)->get($this->url())->assertOk()->assertSee($this->url($liquidacion))
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('DIAGNOSTICO-QA-RESERVADO');
        $this->get($this->url($liquidacion))->assertOk()->assertSee('80000.00 COP')->assertSee('name="_token"', false)
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('DIAGNOSTICO-QA-RESERVADO');
        $this->sinPagos();
        $this->get(route('pagos-particulares.liquidaciones.index'))->assertForbidden();

        $datos = $this->confirmacion($liquidacion, ['valor_pagado' => '80000', 'observacion' => 'Observación privada de QA']);
        $respuesta = $this->post($this->url($liquidacion), $datos)->assertHeader('Cache-Control', 'no-store, private');
        $pago = $liquidacion->pagosParticulares()->sole();
        $respuesta->assertRedirect($this->resultado($liquidacion, $pago));
        $this->assertMatchesRegularExpression('/^PP-[0-9A-HJKMNP-TV-Z]{26}$/', $pago->numero_recibo);
        $this->assertSame('80000.00', $pago->valor_pagado);
        $this->assertSame($datos['fecha_pago'], $pago->fecha_pago->toDateString());
        $this->assertSame('REGISTRADO', $pago->estado);
        $this->assertSame($actor->id, $pago->creador->id);
        $this->assertSame($datos['observacion'], $pago->observacion);
        $this->assertStringNotContainsString($datos['observacion'], $pago->getRawOriginal('observacion'));
        $this->assertSame($liquidacion->getAttributes(), $liquidacion->fresh()->getAttributes());
        $this->assertDatabaseHas('auditorias', [
            'accion' => 'PAGO_PARTICULAR_REGISTRADO', 'entidad' => 'PagoParticular', 'registro_id' => $pago->id,
            'user_id' => $actor->id, 'resultado' => 'EXITO', 'fecha_hora' => $pago->getRawOriginal('fecha_registro'),
        ]);
        $this->assertStringNotContainsString($datos['observacion'], Auditoria::query()->sole()->descripcion);
        $this->get($this->resultado($liquidacion, $pago))->assertOk()->assertSee('PAGADA')->assertSee($pago->numero_recibo)
            ->assertDontSee($datos['observacion'])->assertDontSee('DIAGNOSTICO-QA-RESERVADO')->assertHeader('Cache-Control', 'no-store, private');
        $this->get($this->url())->assertDontSee($this->url($liquidacion));
        $this->assertDatabaseCount('liquidaciones', 1);
    }

    public function test_ca03402_reintentos_muestran_un_solo_pago_vigente_y_una_auditoria(): void
    {
        $actor = $this->administrativo();
        $liquidacion = $this->liquidacion($actor);
        $this->actingAs($actor);
        $datos = $this->confirmacion($liquidacion, ['valor_pagado' => '80000.0']);
        $segunda = $this->confirmacion($liquidacion, ['metodo_pago' => 'TARJETA']);
        $this->post($this->url($liquidacion), $datos)->assertRedirect();
        $pago = PagoParticular::query()->sole();
        $url = $this->resultado($liquidacion, $pago);

        $this->post($this->url($liquidacion), array_replace($datos, ['valor_pagado' => '80000.00']))->assertRedirect($url);
        $this->post($this->url($liquidacion), $segunda)->assertRedirect($url);
        $this->postJson($this->url($liquidacion), array_replace($datos, ['metodo_pago' => 'TRANSFERENCIA']))
            ->assertUnprocessable()->assertJsonValidationErrors('solicitud');
        $this->assertSame($pago->getAttributes(), PagoParticular::query()->sole()->getAttributes());
        $this->assertDatabaseCount('auditorias', 1);
    }

    public function test_ca03403_reemplaza_anulados_sin_reutilizar_recibo_y_reenvio_devuelve_el_historico(): void
    {
        $actor = $this->administrativo();
        $liquidacion = $this->liquidacion($actor);
        $this->actingAs($actor);
        $datos = $this->confirmacion($liquidacion);
        $obsoleta = $this->confirmacion($liquidacion);
        $this->post($this->url($liquidacion), $datos)->assertRedirect();
        $anulado = PagoParticular::query()->sole();
        $historico = $this->resultado($liquidacion, $anulado);
        $this->post($this->url($liquidacion), $obsoleta)->assertRedirect($historico);
        $this->assertNotSame($datos['clave_envio'], $obsoleta['clave_envio']);
        $this->assertDatabaseCount('pagos_particulares', 1);
        $this->assertDatabaseCount('auditorias', 1);
        DB::table('pagos_particulares')->where('id', $anulado->id)->update(['estado' => 'ANULADO']);
        $this->postJson($this->url($liquidacion), $obsoleta)->assertUnprocessable()->assertJsonValidationErrors('intencion');
        $this->assertDatabaseCount('pagos_particulares', 1);
        $this->assertDatabaseCount('auditorias', 1);
        PagoParticular::factory()->for($liquidacion)->create(['estado' => 'ANULADO', 'creado_por' => $actor->id]);
        $this->assertSame('PENDIENTE_PAGO', $liquidacion->estadoParticular());

        $this->post($this->url($liquidacion), $datos)->assertRedirect($historico);
        $this->post($this->url($liquidacion), array_diff_key($datos, ['intencion' => true]))->assertRedirect($historico);
        $this->get($historico)->assertOk()->assertSee('ANULADO')->assertSee('PENDIENTE_PAGO');
        $this->assertDatabaseCount('auditorias', 1);
        $this->post($this->url($liquidacion), $this->confirmacion($liquidacion))->assertRedirect();
        $nuevo = $liquidacion->pagoVigente()->sole();
        $this->assertNotSame($anulado->numero_recibo, $nuevo->numero_recibo);
        $this->assertSame($anulado->numero_recibo, $anulado->fresh()->numero_recibo);
        $this->assertSame('ANULADO', $anulado->fresh()->estado);
        $this->assertSame('PAGADA', $liquidacion->estadoParticular());
        $this->post($this->url($liquidacion), $datos)->assertRedirect($historico);
        $this->assertSame(3, $liquidacion->pagosParticulares()->count());
        $this->assertDatabaseCount('auditorias', 2);
    }

    public function test_rechaza_intencion_ausente_o_manipulada_y_aisla_actor_liquidacion_y_clave(): void
    {
        $actor = $this->administrativo();
        $liquidacion = $this->liquidacion($actor);
        $otra = $this->liquidacion($actor);
        $this->actingAs($actor);
        $datos = $this->confirmacion($liquidacion);
        $url = $this->url($liquidacion);
        foreach ([
            array_diff_key($datos, ['intencion' => true]),
            array_replace($datos, ['intencion' => '0-SIN_PAGO.'.str_repeat('0', 64)]),
            array_replace($datos, ['clave_envio' => (string) Str::uuid()]),
        ] as $payload) {
            $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('intencion');
        }
        $this->postJson($this->url($otra), $datos)->assertUnprocessable()->assertJsonValidationErrors('intencion');
        $this->actingAs(User::factory()->for($actor->rol)->create())->postJson($url, $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('intencion');
        $this->sinPagos();
    }

    public function test_ca03404_rechaza_cuentas_sin_autorizacion_sin_exponer_existencia_o_datos(): void
    {
        $actor = $this->administrativo();
        $liquidacion = $this->liquidacion($actor);
        $this->postJson($this->url($liquidacion), $this->datos())->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $soloLiquidar = Rol::query()->create(['nombre' => 'Solo liquidar QA']);
        $soloLiquidar->permisos()->attach(Permiso::query()->create(['codigo' => 'RF-033', 'descripcion' => 'Liquidar'])->id);
        $this->actingAs(User::factory()->for($soloLiquidar)->create());
        $this->get($this->url())->assertForbidden();
        $this->get($this->url($liquidacion))->assertForbidden()->assertDontSee('DIAGNOSTICO-QA-RESERVADO');
        $this->get($this->url().'/999999')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($this->url($liquidacion), $this->datos())->assertForbidden();
        $actor->forceFill(['estado' => 'inactivo'])->save();
        $this->actingAs($actor)->postJson($this->url($liquidacion), $this->datos())
            ->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
        $this->sinPagos();
    }

    public static function solicitudesInvalidas(): array
    {
        return [
            'inferior' => ['valor_pagado', '79999.99'], 'superior' => ['valor_pagado', '80000.01'],
            'cero' => ['valor_pagado', '0.00'], 'negativo' => ['valor_pagado', '-80000.00'],
            'científico' => ['valor_pagado', '8e4'], 'precisión' => ['valor_pagado', '80000.000'],
            'rango' => ['valor_pagado', '10000000000.00'], 'JSON numérico' => ['valor_pagado', 80000],
            'día inexistente' => ['fecha_pago', '2026-02-31'], 'método' => ['metodo_pago', 'PSE'],
            'confirmación' => ['confirmacion', '0'],
        ];
    }

    #[DataProvider('solicitudesInvalidas')]
    public function test_valida_solicitudes_invalidas(string $campo, mixed $valor): void
    {
        $actor = $this->administrativo();
        $liquidacion = $this->liquidacion($actor);
        $this->actingAs($actor)->postJson($this->url($liquidacion), $this->datos([$campo => $valor]))
            ->assertUnprocessable()->assertJsonValidationErrors($campo)->assertHeader('Cache-Control', 'no-store, private');
        $this->sinPagos();
    }

    public function test_rn24_fecha_desde_el_cierre_local_si_cruza_medianoche(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 03:00:00', 'America/Bogota')->utc());
        $actor = $this->administrativo();
        $liquidacion = $this->liquidacion($actor, [], [
            'fecha_hora_inicio' => Carbon::parse('2026-11-24 23:55:00', 'America/Bogota')->utc(),
            'fecha_hora_fin' => Carbon::parse('2026-11-25 00:10:00', 'America/Bogota')->utc(),
        ]);
        $this->actingAs($actor);
        foreach (['2026-11-24', '2026-11-26'] as $fecha) {
            $this->postJson($this->url($liquidacion), $this->confirmacion($liquidacion, ['fecha_pago' => $fecha]))
                ->assertUnprocessable()->assertJsonValidationErrors('fecha_pago');
        }
        $this->sinPagos();
        $this->postJson($this->url($liquidacion), $this->confirmacion($liquidacion))->assertRedirect();
        $pago = PagoParticular::query()->sole();
        $this->assertSame('2026-11-25', $pago->fecha_pago->toDateString());
        $this->assertSame('2026-11-25 08:00:00', $pago->getRawOriginal('fecha_registro'));
    }

    public function test_rechaza_eps_relaciones_inconsistentes_y_liquidacion_inexistente(): void
    {
        $actor = $this->administrativo();
        $remision = RemisionEps::factory()->create(['estado' => 'USADA']);
        $eps = $this->liquidacion($actor, [
            'modalidad' => 'EPS', 'valor_base' => null, 'remision_id' => $remision->id, 'paciente_id' => $remision->paciente_id,
        ], modalidad: 'EPS', total: '0.00');
        $inconsistente = $this->liquidacion($actor);
        $inconsistente->atencion->cita()->update(['paciente_id' => Paciente::factory()->create()->id]);
        $this->actingAs($actor)->get($this->url())->assertDontSee($this->url($eps))->assertDontSee($this->url($inconsistente));
        $this->get($this->url($eps))->assertUnprocessable();
        $this->postJson($this->url($eps), $this->datos())->assertUnprocessable();
        $this->postJson($this->url($inconsistente), $this->datos())->assertNotFound();
        $this->postJson($this->url().'/9999999', $this->datos())->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('USADA', $remision->fresh()->estado);
        $this->sinPagos();
    }

    public function test_rechaza_campos_manipulados_sin_guardarlos_en_sesion(): void
    {
        $actor = $this->administrativo();
        $liquidacion = $this->liquidacion($actor);
        $payload = $this->datos(['observacion' => 'Observación reservada', 'numero_recibo' => 'PARTICULAR-FALSO', 'estado' => 'ANULADO']);
        $this->actingAs($actor)->post($this->url($liquidacion), $payload)->assertRedirect($this->url($liquidacion))
            ->assertSessionHasErrors('solicitud')->assertSessionMissing('_old_input')->assertHeader('Cache-Control', 'no-store, private');
        $this->sinPagos();
    }

    public function test_resultado_esta_acotado_a_su_liquidacion(): void
    {
        $actor = $this->administrativo();
        $primera = $this->liquidacion($actor);
        $segunda = $this->liquidacion($actor);
        $pago = PagoParticular::factory()->for($primera)->create();
        $this->actingAs($actor)->get($this->resultado($segunda, $pago))
            ->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($pago->numero_recibo);
    }

    public function test_fallo_de_auditoria_revierte_pago_y_no_registra_observacion_ni_sql(): void
    {
        $actor = $this->administrativo();
        $liquidacion = $this->liquidacion($actor);
        $this->actingAs($actor);
        $datos = $this->confirmacion($liquidacion, ['observacion' => 'Texto privado QA']);
        Log::spy();
        Auditoria::creating(fn () => DB::select('SELECT contenido FROM tabla_auditoria_no_disponible'));
        $this->postJson($this->url($liquidacion), $datos)
            ->assertServiceUnavailable()->assertDontSee('Texto privado QA')->assertDontSee('tabla_auditoria_no_disponible')
            ->assertHeader('Cache-Control', 'no-store, private');
        Log::shouldHaveReceived('error')->once()->with('Error de base de datos en RF-034.', [
            'modulo' => 'RF-034', 'operacion' => 'pagos-particulares.pagos.store',
        ]);
        $this->assertSame('PENDIENTE_PAGO', $liquidacion->estadoParticular());
        $this->sinPagos();
    }

    public function test_sqlite_restringe_pago_vigente_recibos_y_dominios(): void
    {
        $actor = $this->administrativo();
        $liquidacion = $this->liquidacion($actor);
        $vigente = PagoParticular::factory()->for($liquidacion)->create();
        foreach ([[], ['estado' => 'ANULADO', 'numero_recibo' => $vigente->numero_recibo],
            ['estado' => 'ANULADO', 'clave_envio' => $vigente->clave_envio], ['estado' => 'PENDIENTE'],
            ['estado' => 'ANULADO', 'valor_pagado' => '0.00'], ['estado' => 'ANULADO', 'valor_pagado' => '1.111'],
            ['estado' => 'ANULADO', 'metodo_pago' => 'PSE']] as $atributos) {
            try {
                PagoParticular::factory()->for($liquidacion)->create($atributos);
                $this->fail('SQLite admitió una fila fuera del contrato.');
            } catch (QueryException $error) {
                $this->assertSame('23000', (string) $error->getCode());
            }
        }
        PagoParticular::factory()->for($liquidacion)->count(2)->create(['estado' => 'ANULADO']);
        $this->assertSame(3, $liquidacion->pagosParticulares()->count());
        $this->assertSame($vigente->id, $liquidacion->pagoVigente()->sole()->id);
    }

    public function test_rf033_muestra_estado_actualizado_sin_cambiar_la_liquidacion(): void
    {
        $actor = $this->administrativo();
        $permiso = Permiso::query()->create(['codigo' => 'RF-033', 'descripcion' => 'Liquidar']);
        $actor->rol->permisos()->attach($permiso);
        $liquidacion = $this->liquidacion($actor);
        $url = route('pagos-particulares.liquidaciones.show', $liquidacion->atencion_medica_id);
        $this->actingAs($actor)->get($url)->assertOk()->assertSee('PENDIENTE_PAGO')->assertSee($this->url($liquidacion));
        $this->postJson($this->url($liquidacion), $this->confirmacion($liquidacion))->assertRedirect();
        $this->get($url)->assertOk()->assertSee('PAGADA')->assertSee('Liquidación #'.$liquidacion->id)
            ->assertSee('80000.00 COP')->assertDontSee($this->url($liquidacion));
        $this->assertSame($liquidacion->getAttributes(), $liquidacion->fresh()->getAttributes());
    }

    public function test_seeder_incremental_idempotente_para_tres_roles(): void
    {
        $this->seed(SecuritySeeder::class);
        $permisos = DB::table('permisos')->count();
        $asignaciones = DB::table('permiso_rol')->count();
        $this->seed(PagoParticularPermissionsSeeder::class);
        $this->seed(PagoParticularPermissionsSeeder::class);
        $this->assertSame(['Administrador', 'Administrativo', 'Médico'], Permiso::query()->where('codigo', 'RF-034')->sole()
            ->roles()->orderBy('nombre')->pluck('nombre')->all());
        $this->assertDatabaseCount('permisos', $permisos + 1);
        $this->assertDatabaseCount('permiso_rol', $asignaciones + 3);
    }

    private function sinPagos(): void
    {
        $this->assertDatabaseCount('pagos_particulares', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }
}
