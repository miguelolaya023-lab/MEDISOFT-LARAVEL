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
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsultaPagoParticularMySqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        $directorio = getenv('MEDISOFT_RF036_MYSQL_QA_DIR');
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

        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
    }

    public function test_mysql_desambigua_documento_antes_de_filtrar_y_conserva_totales_por_paciente(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $base = $this->get($this->url())->assertOk()->viewData('total');
        $documento = '00'.fake()->numerify('############');
        $cc = Paciente::factory()->create(['tipo_documento' => 'CC', 'numero_documento' => $documento]);
        $ti = Paciente::factory()->create(['tipo_documento' => 'TI', 'numero_documento' => $documento]);
        $pagoCc = $this->pago($actor, $cc, '0.10', '2026-11-23');
        $pagoTi = $this->pago($actor, $ti, '0.20', '2026-11-24');
        $filtros = ['fecha_inicial' => '2026-11-23', 'fecha_final' => '2026-11-24', 'estado' => 'REGISTRADO'];
        $huellas = $this->huellas();

        $this->assertFalse($actor->medico()->exists());
        $this->assertFalse($actor->tienePermiso('RF-014'));
        $this->get($this->url())->assertOk()->assertViewHas('total', (string) BigDecimal::of($base)->plus('0.30'));
        $ambiguo = ['documento' => $documento, 'fecha_final' => '2026-11-23'] + $filtros;
        $this->get($this->url($ambiguo))->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('total', null)->assertDontSee($pagoCc->numero_recibo)->assertDontSee($pagoTi->numero_recibo);
        $this->getJson($this->url($ambiguo))->assertUnprocessable()->assertJsonValidationErrors('tipo_documento');
        $this->get($this->url($filtros + ['documento' => substr($documento, 2), 'tipo_documento' => 'CC']))
            ->assertOk()->assertViewHas('total', '0.00');
        $this->getJson($this->url(['tipo_documento' => 'XX', 'documento' => $documento]))
            ->assertUnprocessable()->assertJsonValidationErrors('tipo_documento');
        $this->get(route('pacientes.historial-pagos.resultados', ['pacienteId' => $cc->id] + $filtros))->assertForbidden();
        $actor->rol->permisos()->attach(Permiso::query()->where('codigo', 'RF-014')->sole()->id);

        foreach ([[$cc, $pagoCc, $pagoTi], [$ti, $pagoTi, $pagoCc]] as [$paciente, $pago, $otro]) {
            $this->get($this->url($filtros + ['documento' => $documento, 'tipo_documento' => $paciente->tipo_documento]))
                ->assertOk()->assertViewHas('total', $pago->valor_pagado)->assertSee($pago->numero_recibo)->assertDontSee($otro->numero_recibo);
            $this->get(route('pacientes.historial-pagos.resultados', ['pacienteId' => $paciente->id] + $filtros))
                ->assertOk()->assertSee($pago->numero_recibo)->assertDontSee($otro->numero_recibo)
                ->assertViewHas('historial', fn ($h) => $h->totalParticular === $pago->valor_pagado && $h->movimientos->total() === 1);
        }
        $this->assertSame($huellas, $this->huellas());
    }

    public function test_mysql_conserva_documento_y_tipo_al_paginar_totales_exactos(): void
    {
        $actor = $this->actor();
        $paciente = Paciente::factory()->create(['tipo_documento' => 'CC', 'numero_documento' => '00'.fake()->numerify('############')]);
        for ($i = 0; $i < 21; $i++) {
            $this->pago($actor, $paciente, $i === 0 ? '9999999999.99' : '0.01', '2026-11-24');
        }
        $filtros = ['documento' => $paciente->numero_documento, 'tipo_documento' => 'CC', 'estado' => 'REGISTRADO'];
        $this->actingAs($actor)->get($this->url($filtros))->assertOk()->assertSee('tipo_documento=CC')
            ->assertViewHas('total', '10000000000.19')->assertViewHas('pagos', fn ($p) => $p->count() === 20 && $p->total() === 21);
        $this->get($this->url($filtros + ['page' => 2]))->assertOk()
            ->assertViewHas('total', '10000000000.19')->assertViewHas('pagos', fn ($p) => $p->count() === 1);
    }

    public function test_mysql_filtra_tipo_sin_numero_antes_de_sumar_y_paginar(): void
    {
        $actor = $this->actor();
        $cc = Paciente::factory()->create(['tipo_documento' => 'CC']);
        $otroCc = Paciente::factory()->create(['tipo_documento' => 'CC']);
        $ti = Paciente::factory()->create(['tipo_documento' => 'TI']);
        $fecha = '2044-09-13';
        for ($i = 0; $i < 20; $i++) {
            $this->pago($actor, $cc, '0.10', $fecha);
        }
        $this->pago($actor, $otroCc, '0.20', $fecha);
        $excluido = $this->pago($actor, $ti, '1.00', $fecha);
        $this->pago($actor, $otroCc, '2.00', '2044-09-12');
        $anulado = $this->pago($actor, $otroCc, '3.00', $fecha);
        $anulado->forceFill(['estado' => 'ANULADO'])->save();
        $huellas = $this->huellas();
        $filtros = ['tipo_documento' => 'CC', 'fecha_inicial' => $fecha, 'fecha_final' => $fecha, 'estado' => 'REGISTRADO'];

        $this->actingAs($actor)->get($this->url($filtros))->assertOk()->assertSee('tipo_documento=CC')
            ->assertViewHas('total', '2.20')->assertViewHas('pagos', fn ($p) => $p->count() === 20 && $p->total() === 21)
            ->assertDontSee($excluido->numero_recibo)->assertDontSee($anulado->numero_recibo);
        $this->get($this->url($filtros + ['page' => 2]))->assertOk()
            ->assertViewHas('total', '2.20')->assertViewHas('pagos', fn ($p) => $p->count() === 1);
        $this->get($this->url(['tipo_documento' => 'TI'] + $filtros))->assertOk()
            ->assertViewHas('total', '1.00')->assertSee($excluido->numero_recibo);
        $this->assertSame($huellas, $this->huellas());
    }

    private function actor(): User
    {
        $rol = Rol::query()->create(['nombre' => 'QA RF036 '.$this->name()]);
        $rol->permisos()->attach(Permiso::query()->where('codigo', 'RF-036')->sole()->id);

        return User::factory()->for($rol)->create();
    }

    private function pago(User $actor, Paciente $paciente, string $importe, string $fecha): PagoParticular
    {
        $cita = Cita::factory()->for($paciente)->create(['estado' => 'ATENDIDA', 'valor_base' => $importe]);
        $atencion = AtencionMedica::factory()->for($cita)->create(['estado' => 'FINALIZADA', 'fecha_hora_fin' => now()]);
        $liquidacion = Liquidacion::query()->create([
            'atencion_medica_id' => $atencion->id, 'modalidad' => 'PARTICULAR',
            'valor_total' => $importe, 'fecha_liquidacion' => now(), 'creado_por' => $actor->id,
        ]);

        return PagoParticular::factory()->for($liquidacion)->create([
            'valor_pagado' => $importe, 'fecha_pago' => $fecha, 'creado_por' => $actor->id,
        ])->refresh();
    }

    private function url(array $filtros = []): string
    {
        return route('pagos-particulares.consultas.index', $filtros);
    }

    private function huellas(): array
    {
        $huellas = [];
        foreach (['pagos_particulares', 'liquidaciones', 'pacientes', 'citas', 'atenciones_medicas', 'auditorias'] as $tabla) {
            $huellas[$tabla] = hash('sha256', DB::table($tabla)->orderBy('id')->get()->toJson());
        }

        return $huellas;
    }
}
