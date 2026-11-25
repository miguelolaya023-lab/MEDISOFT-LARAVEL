<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Cita;
use App\Models\Liquidacion;
use App\Models\Paciente;
use App\Models\PagoParticular;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use App\Services\HistorialPagosResultado;
use Database\Seeders\HistorialPagosPermissionsSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HistorialPagosPacienteTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $db = DB::connection();
        if (! $this->app->environment('testing') || $this->app->configurationIsCached() ||
            $db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:' || $db->getConfig('url')) {
            throw new LogicException('La prueba requiere SQLite en memoria.');
        }
    }

    private function actor(string $codigo = 'RF-014'): User
    {
        $rol = Rol::query()->create(['nombre' => 'Administrativo QA']);
        $this->conceder($rol, $codigo);

        return User::factory()->for($rol)->create();
    }

    private function conceder(Rol $rol, string $codigo): void
    {
        $rol->permisos()->attach(Permiso::query()->create(['codigo' => $codigo, 'descripcion' => 'Permiso QA']));
    }

    private function liquidacion(User $actor, Paciente $paciente, string $total = '80000.00'): Liquidacion
    {
        $cita = Cita::factory()->for($paciente)->create(['estado' => 'ATENDIDA', 'valor_base' => '11.00']);
        $atencion = AtencionMedica::factory()->for($cita)->create([
            'estado' => 'FINALIZADA', 'fecha_hora_fin' => now(), 'diagnostico_principal' => 'Diagnóstico privado QA',
        ]);

        return Liquidacion::query()->create([
            'atencion_medica_id' => $atencion->id, 'modalidad' => 'PARTICULAR',
            'valor_total' => $total, 'fecha_liquidacion' => now(), 'creado_por' => $actor->id,
        ]);
    }

    private function pago(Liquidacion $liquidacion, array $datos = []): PagoParticular
    {
        return PagoParticular::factory()->for($liquidacion)->create($datos + [
            'valor_pagado' => $liquidacion->valor_total, 'fecha_pago' => '2026-11-24', 'fecha_registro' => '2026-11-25',
            'observacion' => 'Observación privada QA', 'creado_por' => $liquidacion->creado_por,
        ])->refresh();
    }

    private function url(Paciente|int $paciente, array $filtros = []): string
    {
        return route('pacientes.historial-pagos.resultados', ['pacienteId' => $paciente] + $filtros);
    }

    private function rango(): array
    {
        return ['fecha_inicial' => '2026-11-23', 'fecha_final' => '2026-11-24'];
    }

    public function test_fallback_de_ca01401_solo_rf014_puede_localizar_por_documento_y_leer_pagos_del_paciente(): void
    {
        $actor = $this->actor();
        $principal = Paciente::factory()->create(['numero_documento' => '001234', 'tipo_documento' => 'CC']);
        $otro = Paciente::factory()->create(['numero_documento' => '001234', 'tipo_documento' => 'TI']);
        $pago = $this->pago($this->liquidacion($actor, $principal));
        $ajeno = $this->pago($this->liquidacion($actor, $otro));

        $this->assertFalse($actor->medico()->exists());
        $this->actingAs($actor)->get(route('pacientes.historial-pagos.buscar'))->assertOk()->assertSee('Buscar paciente');
        $this->get(route('pacientes.historial-pagos.localizar', ['tipo_documento' => 'CC', 'documento' => '001234']))
            ->assertRedirect(route('pacientes.historial-pagos.formulario', $principal))
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('pacientes.show', $principal))->assertForbidden();
        $this->get(route('pagos-particulares.consultas.index'))->assertForbidden();
        $this->get($this->url($principal, $this->rango()))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($pago->numero_recibo)->assertDontSee($ajeno->numero_recibo)->assertDontSee('Ver comprobante')
            ->assertDontSee('Diagnóstico privado QA')->assertDontSee('Observación privada QA')->assertSee('Sprint 4')
            ->assertViewHas('historial', fn (HistorialPagosResultado $h) => $h->totalParticular === '80000.00' && $h->totalCuotaModeradora === '0.00');
        $this->conceder($actor->rol, 'RF-035');
        $this->get($this->url($principal, $this->rango()))->assertSee('Ver comprobante');
    }

    public function test_ca01402_anulado_y_sustituto_con_totales_separados_exactos(): void
    {
        $actor = $this->actor();
        $paciente = Paciente::factory()->create();
        $base = $this->liquidacion($actor, $paciente, '0.10');
        $anulado = $this->pago($base, ['estado' => 'ANULADO', 'fecha_pago' => '2026-11-23']);
        $sustituto = $this->pago($base, ['fecha_pago' => '2026-11-23']);
        $ultimo = $this->pago($this->liquidacion($actor, $paciente, '0.20'));
        $fuera = $this->pago($this->liquidacion($actor, $paciente), ['fecha_pago' => '2026-11-25']);
        $this->liquidacion($actor, $paciente);
        $rango = $this->rango();

        $this->actingAs($actor)->get($this->url($paciente, $rango))->assertOk()
            ->assertSeeInOrder([$ultimo->numero_recibo, $sustituto->numero_recibo, $anulado->numero_recibo])
            ->assertDontSee($fuera->numero_recibo)
            ->assertViewHas('historial', fn (HistorialPagosResultado $h) => $h->totalParticular === '0.30' && $h->totalCuotaModeradora === '0.00');
        $this->get($this->url($paciente, $rango + ['tipo_cobro' => 'PARTICULAR', 'estado' => 'ANULADO']))
            ->assertSee($anulado->numero_recibo)->assertDontSee($sustituto->numero_recibo)
            ->assertViewHas('historial', fn (HistorialPagosResultado $h) => $h->totalParticular === '0.00');
        $this->get($this->url($paciente, $rango + ['estado' => 'REGISTRADO']))->assertDontSee($anulado->numero_recibo)
            ->assertViewHas('historial', fn (HistorialPagosResultado $h) => $h->totalParticular === '0.30');
        $this->get($this->url($paciente, $rango + ['tipo_cobro' => 'CUOTA_MODERADORA']))->assertOk()->assertDontSee($sustituto->numero_recibo)
            ->assertViewHas('historial', fn (HistorialPagosResultado $h) => $h->movimientos->isEmpty() &&
                $h->totalParticular === '0.00' && $h->totalCuotaModeradora === '0.00');
        $this->assertDatabaseCount('pagos_particulares', 4);
        $this->assertDatabaseCount('auditorias', 0);
        $this->assertSame($anulado->getAttributes(), $anulado->fresh()->getAttributes());
    }

    public function test_ca01403_paciente_existente_sin_movimientos_y_paciente_inexistente(): void
    {
        $this->actingAs($this->actor())->get($this->url(Paciente::factory()->create(), $this->rango()))
            ->assertOk()->assertSee('No se encontraron movimientos')
            ->assertViewHas('historial', fn (HistorialPagosResultado $h) => $h->totalParticular === '0.00' && $h->totalCuotaModeradora === '0.00');
        $this->get($this->url(999, $this->rango()))->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('pacientes.historial-pagos.localizar', ['tipo_documento' => 'CC', 'documento' => 'NO EXISTE']))
            ->assertNotFound()->assertDontSee('NO EXISTE');
    }

    public function test_ca01404_rechaza_acceso_directo_sin_sesion_permiso_o_cuenta_activa(): void
    {
        $actor = $this->actor('RF-036');
        $paciente = Paciente::factory()->create();
        $pago = $this->pago($this->liquidacion($actor, $paciente));
        $url = $this->url($paciente, $this->rango());
        $this->get($url)->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($actor)->get($url)->assertForbidden()->assertDontSee($pago->numero_recibo)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('pacientes.historial-pagos.buscar'))->assertForbidden();
        $this->conceder($actor->rol, 'RF-014');
        $actor->forceFill(['estado' => 'inactivo'])->save();
        $this->get($url)->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('pagos_particulares', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function filtrosInvalidos(): array
    {
        return [
            'sin rango' => [[], 'fecha_inicial'],
            'solo inicio' => [['fecha_inicial' => '2026-11-24'], 'fecha_final'],
            'solo fin' => [['fecha_final' => '2026-11-24'], 'fecha_inicial'],
            'invertido' => [['fecha_inicial' => '2026-11-24', 'fecha_final' => '2026-11-23'], 'fecha_final'],
            'fecha inválida' => [['fecha_inicial' => '2026-11-30', 'fecha_final' => '2026-11-31'], 'fecha_final'],
            'estado inválido' => [['estado' => 'QA-PRIVADO'], 'estado'],
            'tipo inválido' => [['tipo_cobro' => 'QA-PRIVADO'], 'tipo_cobro'],
            'paciente manipulado' => [['paciente_id' => 'QA-PRIVADO'], 'filtros'],
            'documento alternativo' => [['documento' => 'QA-PRIVADO'], 'filtros'],
        ];
    }

    #[DataProvider('filtrosInvalidos')]
    public function test_filtros_invalidos_sin_exponer_entradas(array $filtros, string $campo): void
    {
        if (in_array($campo, ['estado', 'tipo_cobro', 'filtros'], true)) {
            $filtros += $this->rango();
        }
        $this->actingAs($this->actor());
        $url = $this->url(Paciente::factory()->create(), $filtros);
        $this->get($url)->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee('QA-PRIVADO');
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->assertDatabaseCount('pagos_particulares', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_paginacion_con_total_exacto_y_alcance_del_paciente(): void
    {
        $actor = $this->actor();
        $paciente = Paciente::factory()->create();
        for ($i = 0; $i < 21; $i++) {
            $this->pago($this->liquidacion($actor, $paciente, $i === 0 ? '9999999999.99' : '0.01'));
        }
        $this->pago($this->liquidacion($actor, Paciente::factory()->create()));
        $filtros = $this->rango() + ['tipo_cobro' => 'PARTICULAR', 'estado' => 'REGISTRADO'];
        $this->actingAs($actor)->get($this->url($paciente, $filtros))->assertOk()->assertSee('tipo_cobro=PARTICULAR')
            ->assertViewHas('historial', fn (HistorialPagosResultado $h) => $h->totalParticular === '10000000000.19' &&
                $h->movimientos->count() === 20 && $h->movimientos->total() === 21);
        $this->get($this->url($paciente, $filtros + ['page' => 2]))->assertOk()
            ->assertViewHas('historial', fn (HistorialPagosResultado $h) => $h->totalParticular === '10000000000.19' && $h->movimientos->count() === 1);
    }

    public function test_seeder_incremental_idempotente(): void
    {
        $this->seed(SecuritySeeder::class);
        $antes = DB::table('permiso_rol')->count();
        $this->seed(HistorialPagosPermissionsSeeder::class);
        $this->seed(HistorialPagosPermissionsSeeder::class);
        $permiso = Permiso::query()->where('codigo', 'RF-014')->sole();
        $this->assertSame(['Administrador', 'Administrativo', 'Médico'], $permiso->roles()->orderBy('nombre')->pluck('nombre')->all());
        $this->assertDatabaseCount('permiso_rol', $antes + 3);
    }
}
