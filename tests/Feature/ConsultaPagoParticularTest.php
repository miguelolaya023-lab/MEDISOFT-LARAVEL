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
use Database\Seeders\ConsultaPagoParticularPermissionsSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConsultaPagoParticularTest extends TestCase
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

    private function actor(string $permiso = 'RF-036'): User
    {
        $rol = Rol::query()->create(['nombre' => 'Administrativo RF036']);
        $rol->permisos()->attach(Permiso::query()->create(['codigo' => $permiso, 'descripcion' => 'Permiso QA']));

        return User::factory()->for($rol)->create();
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

    private function url(array $filtros = []): string
    {
        return route('pagos-particulares.consultas.index', $filtros);
    }

    public function test_ca03601_filtra_documento_exacto_sin_perder_ceros_ni_exponer_datos_privados(): void
    {
        $actor = $this->actor();
        $this->assertFalse($actor->medico()->exists());
        $this->assertFalse($actor->tienePermiso('RF-035'));
        $uno = Paciente::factory()->create(['numero_documento' => '001234']);
        $otro = Paciente::factory()->create(['numero_documento' => '1234']);
        $pago = $this->pago($this->liquidacion($actor, $uno));
        $ajeno = $this->pago($this->liquidacion($actor, $otro));

        $this->actingAs($actor)->get($this->url(['documento' => '001234']))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee($pago->numero_recibo)->assertDontSee($ajeno->numero_recibo)->assertViewHas('total', '80000.00')
            ->assertDontSee('Ver comprobante')->assertDontSee('Observación privada QA')->assertDontSee('Diagnóstico privado QA');
        $actor->rol->permisos()->attach(Permiso::query()->create(['codigo' => 'RF-035', 'descripcion' => 'Emitir']));
        $this->get($this->url(['documento' => '001234']))->assertSee('Ver comprobante');
    }

    public function test_ca03602_anulado_visible_y_excluido_del_total_con_filtros_inclusivos_y_combinados(): void
    {
        $actor = $this->actor();
        $paciente = Paciente::factory()->create(['numero_documento' => '00042']);
        $base = $this->liquidacion($actor, $paciente, '0.10');
        $anulado = $this->pago($base, ['estado' => 'ANULADO', 'fecha_pago' => '2026-11-23']);
        $primero = $this->pago($base, ['fecha_pago' => '2026-11-23']);
        $ultimo = $this->pago($this->liquidacion($actor, $paciente, '0.20'));
        $fuera = $this->pago($this->liquidacion($actor, $paciente), ['fecha_pago' => '2026-11-25']);
        $inconsistente = $this->pago($this->liquidacion($actor, $paciente), ['valor_pagado' => '0.01']);
        $this->liquidacion($actor, $paciente);
        $filtros = ['fecha_inicial' => '2026-11-23', 'fecha_final' => '2026-11-24', 'documento' => '00042'];

        $this->actingAs($actor)->get($this->url())->assertOk()->assertSee($fuera->numero_recibo)
            ->assertViewHas('total', '80000.30');
        $this->get($this->url($filtros))->assertOk()->assertViewHas('total', '0.30')
            ->assertSeeInOrder([$ultimo->numero_recibo, $primero->numero_recibo, $anulado->numero_recibo])
            ->assertDontSee($fuera->numero_recibo)->assertDontSee($inconsistente->numero_recibo);
        $this->get($this->url($filtros + ['estado' => 'ANULADO']))->assertOk()->assertViewHas('total', '0.00')
            ->assertSee($anulado->numero_recibo)->assertDontSee($primero->numero_recibo);
        $this->get($this->url($filtros + ['estado' => 'REGISTRADO']))->assertOk()->assertViewHas('total', '0.30')
            ->assertDontSee($anulado->numero_recibo);
        $this->assertSame($anulado->getAttributes(), $anulado->fresh()->getAttributes());
        $this->assertDatabaseCount('pagos_particulares', 5);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_ca03601_desambigua_tipo_antes_de_filtrar_pagos_y_rf014_conserva_identidad(): void
    {
        $actor = $this->actor();
        $documento = '001234';
        $cc = Paciente::factory()->create(['tipo_documento' => 'CC', 'numero_documento' => $documento]);
        $ti = Paciente::factory()->create(['tipo_documento' => 'TI', 'numero_documento' => $documento]);
        $pagoCc = $this->pago($this->liquidacion($actor, $cc, '0.10'), ['fecha_pago' => '2026-11-23']);
        $pagoTi = $this->pago($this->liquidacion($actor, $ti, '0.20'));
        $filtros = ['fecha_inicial' => '2026-11-23', 'fecha_final' => '2026-11-24', 'estado' => 'REGISTRADO'];

        $this->actingAs($actor)->get($this->url())->assertOk()->assertViewHas('total', '0.30')
            ->assertSee($pagoCc->numero_recibo)->assertSee($pagoTi->numero_recibo);
        $ambiguo = ['documento' => $documento, 'fecha_final' => '2026-11-23'] + $filtros;
        $this->get($this->url($ambiguo))->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Seleccione el tipo de documento')->assertDontSee($pagoCc->numero_recibo)->assertDontSee($pagoTi->numero_recibo)
            ->assertViewHas('total', null);
        $this->getJson($this->url($ambiguo))->assertUnprocessable()->assertJsonValidationErrors('tipo_documento');
        $this->get($this->url($filtros + ['documento' => '1234', 'tipo_documento' => 'CC']))
            ->assertOk()->assertViewHas('total', '0.00');
        $this->assertDatabaseCount('pagos_particulares', 2);
        $this->assertDatabaseCount('auditorias', 0);

        $actor->rol->permisos()->attach(Permiso::query()->create(['codigo' => 'RF-014', 'descripcion' => 'Historial']));
        foreach ([[$cc, $pagoCc, $pagoTi, '0.10'], [$ti, $pagoTi, $pagoCc, '0.20']] as [$paciente, $pago, $otro, $total]) {
            $this->get($this->url($filtros + ['documento' => $documento, 'tipo_documento' => $paciente->tipo_documento]))
                ->assertOk()->assertViewHas('total', $total)->assertSee($pago->numero_recibo)->assertDontSee($otro->numero_recibo);
            $this->get(route('pacientes.historial-pagos.resultados', ['pacienteId' => $paciente->id] + $filtros))
                ->assertOk()->assertSee($pago->numero_recibo)->assertDontSee($otro->numero_recibo)
                ->assertViewHas('historial', fn ($h) => $h->totalParticular === $total && $h->movimientos->total() === 1);
        }
        $this->assertDatabaseCount('pagos_particulares', 2);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_ca03603_sin_pagos_o_documento_inexistente_muestra_cero(): void
    {
        $this->actingAs($this->actor())->get($this->url())->assertOk()->assertSee('No se encontraron pagos')
            ->assertViewHas('total', '0.00');
        $this->get($this->url(['documento' => '000000000']))->assertOk()->assertSee('No se encontraron pagos')
            ->assertViewHas('total', '0.00');
    }

    public function test_filtra_solo_por_tipo_documental_y_combina_fecha_y_estado(): void
    {
        $actor = $this->actor();
        $primero = Paciente::factory()->create(['tipo_documento' => 'CC']);
        $segundo = Paciente::factory()->create(['tipo_documento' => 'CC']);
        $otroTipo = Paciente::factory()->create(['tipo_documento' => 'TI']);
        $pago = $this->pago($this->liquidacion($actor, $primero, '0.10'));
        $otroCc = $this->pago($this->liquidacion($actor, $segundo, '0.20'), ['fecha_pago' => '2026-11-23']);
        $anulado = $this->pago($this->liquidacion($actor, $segundo), ['estado' => 'ANULADO']);
        $pagoTi = $this->pago($this->liquidacion($actor, $otroTipo));

        $this->actingAs($actor)->get($this->url(['tipo_documento' => 'CC']))->assertOk()
            ->assertViewHas('total', '0.30')->assertSee($pago->numero_recibo)->assertSee($otroCc->numero_recibo)
            ->assertSee($anulado->numero_recibo)->assertDontSee($pagoTi->numero_recibo);
        $this->get($this->url([
            'tipo_documento' => 'CC', 'fecha_inicial' => '2026-11-24', 'fecha_final' => '2026-11-24', 'estado' => 'REGISTRADO',
        ]))->assertOk()->assertViewHas('total', '0.10')->assertSee($pago->numero_recibo)
            ->assertDontSee($otroCc->numero_recibo)->assertDontSee($anulado->numero_recibo)->assertDontSee($pagoTi->numero_recibo);
        $this->get($this->url(['tipo_documento' => 'PA']))->assertOk()->assertViewHas('total', '0.00');
        $this->assertDatabaseCount('pagos_particulares', 4);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_ca03604_rechaza_sin_login_permiso_o_cuenta_activa(): void
    {
        $actor = $this->actor('RF-035');
        $pago = $this->pago($this->liquidacion($actor, Paciente::factory()->create()));
        $this->get($this->url())->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($actor)->get($this->url())->assertForbidden()->assertDontSee($pago->numero_recibo)
            ->assertHeader('Cache-Control', 'no-store, private');
        $actor->rol->permisos()->attach(Permiso::query()->create(['codigo' => 'RF-036', 'descripcion' => 'Consultar']));
        $actor->forceFill(['estado' => 'inactivo'])->save();
        $this->get($this->url())->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('pagos_particulares', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function filtrosInvalidos(): array
    {
        return [
            'solo inicio' => [['fecha_inicial' => '2026-11-24'], 'fecha_final'],
            'solo fin' => [['fecha_final' => '2026-11-24'], 'fecha_inicial'],
            'invertido' => [['fecha_inicial' => '2026-11-24', 'fecha_final' => '2026-11-23'], 'fecha_final'],
            'fecha inválida' => [['fecha_inicial' => '2026-11-31', 'fecha_final' => '2026-11-24'], 'fecha_inicial'],
            'estado inválido' => [['estado' => 'QA-PRIVADO'], 'estado'],
            'documento inválido' => [['documento' => ['QA-PRIVADO']], 'documento'],
            'tipo inválido' => [['tipo_documento' => 'QA-PRIVADO', 'documento' => '001234'], 'tipo_documento'],
            'campo adicional' => [['campo' => 'QA-PRIVADO'], 'filtros'],
        ];
    }

    #[DataProvider('filtrosInvalidos')]
    public function test_filtros_invalidos_sin_repetir_datos_en_la_respuesta(array $filtros, string $campo): void
    {
        $this->actingAs($this->actor())->get($this->url($filtros))->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee('QA-PRIVADO');
        $this->getJson($this->url($filtros))->assertUnprocessable()->assertJsonValidationErrors($campo)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('pagos_particulares', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_total_exacto_del_conjunto_completo_y_filtros_en_paginacion(): void
    {
        $actor = $this->actor();
        $paciente = Paciente::factory()->create(['numero_documento' => '0036']);
        for ($i = 0; $i < 21; $i++) {
            $this->pago($this->liquidacion($actor, $paciente, $i === 0 ? '9999999999.99' : '0.01'));
        }
        $filtros = ['documento' => '0036', 'tipo_documento' => 'CC', 'estado' => 'REGISTRADO'];
        $this->actingAs($actor)->get($this->url($filtros))->assertOk()->assertViewHas('total', '10000000000.19')
            ->assertSee('documento=0036')->assertSee('tipo_documento=CC')->assertViewHas('pagos', fn ($p) => $p->count() === 20 && $p->total() === 21);
        $this->get($this->url($filtros + ['page' => 2]))->assertOk()->assertViewHas('total', '10000000000.19')
            ->assertViewHas('pagos', fn ($p) => $p->count() === 1);
        $this->get($this->url(['tipo_documento' => 'CC', 'page' => 2]))->assertOk()
            ->assertSee('tipo_documento=CC')->assertViewHas('total', '10000000000.19')
            ->assertViewHas('pagos', fn ($p) => $p->count() === 1 && $p->total() === 21);
    }

    public function test_seeder_incremental_idempotente(): void
    {
        $this->seed(SecuritySeeder::class);
        $antes = DB::table('permiso_rol')->count();
        $this->seed(ConsultaPagoParticularPermissionsSeeder::class);
        $this->seed(ConsultaPagoParticularPermissionsSeeder::class);
        $permiso = Permiso::query()->where('codigo', 'RF-036')->sole();
        $this->assertSame(['Administrador', 'Administrativo', 'Médico'], $permiso->roles()->orderBy('nombre')->pluck('nombre')->all());
        $this->assertDatabaseCount('permiso_rol', $antes + 3);
    }
}
