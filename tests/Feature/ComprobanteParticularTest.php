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
use Database\Seeders\ComprobanteParticularPermissionsSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class ComprobanteParticularTest extends TestCase
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

    private function usuario(string $permiso = 'RF-035'): User
    {
        $rol = Rol::query()->create(['nombre' => 'Administrativo QA']);
        $rol->permisos()->attach(Permiso::query()->create(['codigo' => $permiso, 'descripcion' => 'Permiso QA']));

        return User::factory()->for($rol)->create();
    }

    private function pago(User $actor, string $estado = 'REGISTRADO'): PagoParticular
    {
        $paciente = Paciente::factory()->create(['nombres' => 'Paciente <ficticio>', 'numero_documento' => '99000035']);
        $cita = Cita::factory()->for($paciente)->create(['estado' => 'ATENDIDA', 'valor_base' => '11.00']);
        $atencion = AtencionMedica::factory()->for($cita)->create([
            'estado' => 'FINALIZADA', 'fecha_hora_fin' => now(), 'diagnostico_principal' => 'Diagnóstico privado QA',
        ]);
        $liquidacion = Liquidacion::query()->create([
            'atencion_medica_id' => $atencion->id, 'modalidad' => 'PARTICULAR',
            'valor_total' => '80000.00', 'fecha_liquidacion' => now(), 'creado_por' => $actor->id,
        ]);

        return PagoParticular::factory()->for($liquidacion)->create([
            'estado' => $estado, 'fecha_pago' => '2026-11-24', 'observacion' => 'Observación privada QA', 'creado_por' => $actor->id,
        ])->refresh();
    }

    private function url(string $recibo): string
    {
        return route('pagos-particulares.comprobantes.buscar', ['numero_recibo' => $recibo]);
    }

    public function test_ca03501_usuario_solo_rf035_emite_con_datos_persistidos_sin_exponer_informacion_privada(): void
    {
        $actor = $this->usuario();
        $pago = $this->pago($actor);
        $this->assertFalse($actor->medico()->exists());
        $this->assertFalse($actor->tienePermiso('RF-034'));
        $this->actingAs($actor)->get(route('pagos-particulares.comprobantes.index'))->assertOk()->assertSee('Buscar recibo');
        $this->get($this->url($pago->numero_recibo))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($pago->numero_recibo)->assertSee('80000.00 COP')->assertSee('Paciente <ficticio>')
            ->assertSee('99000035')->assertSee('24/11/2026')->assertSee('EFECTIVO')->assertSee('REGISTRADO')
            ->assertDontSee('Paciente <ficticio>', false)->assertDontSee('Observación privada QA')
            ->assertDontSee('Diagnóstico privado QA')->assertDontSee('11.00 COP');
    }

    public function test_ca03502_consulta_repetida_no_escribe_ni_duplica_registros(): void
    {
        $actor = $this->usuario();
        $pago = $this->pago($actor);
        $this->actingAs($actor);
        for ($i = 0; $i < 2; $i++) {
            $this->get($this->url($pago->numero_recibo))->assertOk()->assertSee($pago->numero_recibo)
                ->assertSee('window.print()', false);
        }
        $this->assertDatabaseCount('pagos_particulares', 1);
        $this->assertDatabaseCount('liquidaciones', 1);
        $this->assertDatabaseCount('auditorias', 0);
        $this->assertSame($pago->getAttributes(), $pago->fresh()->getAttributes());
    }

    public function test_ca03503_comprobante_anulado_conserva_recibo_y_marca_dentro_del_documento(): void
    {
        $actor = $this->usuario();
        $pago = $this->pago($actor, 'ANULADO');
        $this->actingAs($actor)->get($this->url($pago->numero_recibo))->assertOk()
            ->assertSeeInOrder(['med-comprobante', $pago->numero_recibo, 'ANULADO', 'Pago histórico sin vigencia', '80000.00 COP', 'med-comprobante-acciones']);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_ca03504_rechaza_acceso_directo_e_invalido_sin_exponer_datos(): void
    {
        $actor = $this->usuario('RF-034');
        $pago = $this->pago($actor);
        $this->get($this->url($pago->numero_recibo))->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($actor)->get($this->url($pago->numero_recibo))->assertForbidden()
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('99000035');
        $this->get(route('pagos-particulares.pagos.resultado', [$pago->liquidacion_id, $pago->id]))
            ->assertOk()->assertDontSee('Ver comprobante');
        $actor->rol->permisos()->attach(Permiso::query()->create(['codigo' => 'RF-035', 'descripcion' => 'Emitir QA']));
        $this->get(route('pagos-particulares.pagos.resultado', [$pago->liquidacion_id, $pago->id]))
            ->assertSee('Ver comprobante');
        $this->get($this->url('incorrecto-privado'))->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Ingrese un número de recibo completo y válido.')->assertDontSee('incorrecto-privado');
        $this->getJson($this->url('PP-8'.str_repeat('0', 25)))->assertUnprocessable()->assertJsonValidationErrors('numero_recibo')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get($this->url('PP-'.Str::ulid()))->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('No se encontró el recibo solicitado.');
        $actor->forceFill(['estado' => 'inactivo'])->save();
        $this->get($this->url($pago->numero_recibo))->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('pagos_particulares', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_rechaza_relaciones_inconsistentes_y_modalidad_incompatible(): void
    {
        $actor = $this->usuario();
        $pago = $this->pago($actor);
        $this->actingAs($actor);
        $pago->liquidacion()->update(['modalidad' => 'EPS']);
        $this->get($this->url($pago->numero_recibo))->assertNotFound()->assertDontSee('99000035');
        $pago->liquidacion()->update(['modalidad' => 'PARTICULAR']);
        $pago->liquidacion->atencion->cita()->update(['paciente_id' => Paciente::factory()->create()->id]);
        $this->get($this->url($pago->numero_recibo))->assertNotFound()->assertDontSee('99000035');
    }

    public function test_seeder_incremental_idempotente(): void
    {
        $this->seed(SecuritySeeder::class);
        $antes = DB::table('permiso_rol')->count();
        $this->seed(ComprobanteParticularPermissionsSeeder::class);
        $this->seed(ComprobanteParticularPermissionsSeeder::class);
        $permiso = Permiso::query()->where('codigo', 'RF-035')->sole();
        $this->assertSame(['Administrador', 'Administrativo', 'Médico'], $permiso->roles()->orderBy('nombre')->pluck('nombre')->all());
        $this->assertDatabaseCount('permiso_rol', $antes + 3);
    }
}
