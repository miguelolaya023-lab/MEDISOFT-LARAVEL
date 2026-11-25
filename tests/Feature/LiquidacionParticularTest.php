<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\Liquidacion;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\LiquidacionParticularPermissionsSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiquidacionParticularTest extends TestCase
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
        $this->seed([SecuritySeeder::class, LiquidacionParticularPermissionsSeeder::class]);

        return User::factory()->for(Rol::query()->where('nombre', 'Administrativo')->sole())->create();
    }

    private function atencion(array $cita = [], array $atencion = []): AtencionMedica
    {
        return AtencionMedica::factory()
            ->for(Cita::factory()->state($cita + ['estado' => 'ATENDIDA', 'valor_base' => '80000.00']))
            ->create($atencion + ['estado' => 'FINALIZADA', 'fecha_hora_fin' => now(), 'diagnostico_principal' => 'DATO-CLINICO-QA-RESERVADO'])->refresh();
    }

    private function url(?AtencionMedica $atencion = null): string
    {
        return $atencion === null
            ? route('pagos-particulares.liquidaciones.index')
            : route('pagos-particulares.liquidaciones.show', $atencion->id);
    }

    private function confirmacion(AtencionMedica $atencion): array
    {
        $respuesta = $this->get($this->url($atencion))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        return ['confirmacion' => '1', 'version_importe' => $respuesta->viewData('version')];
    }

    public function test_ca03301_liquida_importe_exacto_con_auditoria_y_sin_perfil_medico(): void
    {
        $actor = $this->administrativo();
        $this->assertFalse($actor->medico()->exists());
        $atencion = $this->atencion();
        $this->actingAs($actor)->get($this->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Pagos particulares')->assertSee($this->url($atencion))->assertDontSee('DATO-CLINICO-QA-RESERVADO');
        $confirmacion = $this->confirmacion($atencion);
        $this->get($this->url($atencion))->assertSee('80000.00 COP')->assertSee('Confirmar liquidación')->assertSee('name="_token"', false)
            ->assertDontSee('DATO-CLINICO-QA-RESERVADO');
        $this->assertDatabaseCount('liquidaciones', 0);
        $this->assertDatabaseCount('auditorias', 0);
        $this->post($this->url($atencion), $confirmacion)->assertRedirect($this->url($atencion))->assertHeader('Cache-Control', 'no-store, private');
        $liquidacion = Liquidacion::query()->sole();
        $this->assertSame(['80000.00', 'PARTICULAR', 'PENDIENTE_PAGO', $actor->id], [
            $liquidacion->valor_total, $liquidacion->modalidad, $liquidacion->estadoParticular(), $liquidacion->creador->id,
        ]);
        $this->assertTrue($liquidacion->atencion->is($atencion));
        $this->assertSame($atencion->getAttributes(), $atencion->fresh()->getAttributes());
        $this->assertDatabaseHas('auditorias', [
            'user_id' => $actor->id, 'entidad' => 'Liquidacion', 'registro_id' => $liquidacion->id,
            'accion' => 'LIQUIDACION_PARTICULAR_REGISTRADA', 'resultado' => 'EXITO',
            'fecha_hora' => $liquidacion->getRawOriginal('fecha_liquidacion'),
        ]);
        $this->assertDatabaseCount('auditorias', 1);
        $this->get($this->url($atencion))->assertOk()->assertSee('PENDIENTE_PAGO')->assertDontSee('Confirmar liquidación');
    }

    public function test_ca03302_repeticion_conserva_id_importe_fecha_y_autoria_original(): void
    {
        $this->actingAs($this->administrativo());
        $atencion = $this->atencion(['valor_base' => '9999999999.99']);
        $confirmacion = $this->confirmacion($atencion);
        $this->post($this->url($atencion), $confirmacion)->assertRedirect($this->url($atencion));
        $original = Liquidacion::query()->sole();
        $this->assertSame('9999999999.99', $original->valor_total);
        $atencion->cita()->update(['valor_base' => '0.01']);
        $this->actingAs($this->administrativo());
        $this->travel(5)->minutes();
        $this->post($this->url($atencion), $confirmacion)->assertRedirect($this->url($atencion));
        $this->get($this->url($atencion))->assertSee('9999999999.99 COP')->assertDontSee('0.01 COP');
        $this->get($this->url())->assertSee('Ver liquidación');
        $this->assertSame($original->getAttributes(), Liquidacion::query()->sole()->getAttributes());
        $this->assertDatabaseCount('auditorias', 1);
    }

    public function test_ca03303_rechaza_eps_y_conserva_remision_atencion_y_cita(): void
    {
        $this->actingAs($this->administrativo());
        $remision = RemisionEps::factory()->create(['estado' => 'USADA']);
        $atencion = $this->atencion([
            'modalidad' => 'EPS', 'remision_id' => $remision->id, 'valor_base' => null, 'paciente_id' => $remision->paciente_id,
        ]);
        $this->get($this->url())->assertDontSee($this->url($atencion));
        $this->get($this->url($atencion))->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($this->url($atencion), ['confirmacion' => '1', 'version_importe' => str_repeat('a', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('atencion');
        $this->assertSame('USADA', $remision->fresh()->estado);
        $this->assertSame($remision->id, $atencion->cita()->value('remision_id'));
        $this->assertSame('FINALIZADA', $atencion->fresh()->estado);
        $this->sinEscrituras();
    }

    public function test_ca03304_requiere_autorizacion_en_listado_y_por_url_directa(): void
    {
        $atencion = $this->atencion();
        $datos = ['confirmacion' => '1', 'version_importe' => str_repeat('a', 64)];
        $this->get($this->url($atencion))->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($this->url($atencion), $datos)->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->administrator()->create());
        $this->get($this->url())->assertForbidden();
        $this->get($this->url($atencion))->assertForbidden()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('DATO-CLINICO-QA-RESERVADO');
        $this->postJson($this->url($atencion), $datos)->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $actor = $this->administrativo();
        $actor->forceFill(['estado' => 'inactivo'])->save();
        $this->actingAs($actor)->postJson($this->url($atencion), $datos)
            ->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
        $this->sinEscrituras();
    }

    public function test_solicitud_invalida_no_acepta_importes_ids_ni_guarda_la_entrada(): void
    {
        $this->actingAs($this->administrativo());
        $atencion = $this->atencion();
        $confirmacion = $this->confirmacion($atencion);
        $this->postJson($this->url($atencion), array_diff_key($confirmacion, ['confirmacion' => true]))
            ->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private')->assertJsonValidationErrors('confirmacion');
        $forjada = $confirmacion + ['valor_total' => '5.01', 'valor_base' => '5.01', 'paciente_id' => null, 'diagnostico' => 'DATO-CLINICO-QA-RESERVADO'];
        $this->post($this->url($atencion), $forjada)->assertRedirect($this->url($atencion))
            ->assertHeader('Cache-Control', 'no-store, private')->assertSessionHasErrors('solicitud')->assertSessionMissing('_old_input');
        $this->sinEscrituras();
    }

    public function test_rechaza_confirmacion_de_importe_obsoleto_o_de_otra_atencion(): void
    {
        $this->actingAs($this->administrativo());
        $atencion = $this->atencion();
        $primera = $this->confirmacion($atencion);
        $otra = $this->atencion();
        $this->postJson($this->url($otra), $primera)->assertUnprocessable()->assertJsonValidationErrors('version_importe');
        $atencion->cita()->update(['valor_base' => '80000.01']);
        $this->postJson($this->url($atencion), $primera)
            ->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private')->assertJsonValidationErrors('version_importe');
        $this->sinEscrituras();
        $this->post($this->url($atencion), $this->confirmacion($atencion))->assertRedirect($this->url($atencion));
        $this->assertSame('80000.01', Liquidacion::query()->sole()->valor_total);
    }

    public function test_rechaza_atencion_en_curso_cita_incompatible_y_relaciones_inconsistentes(): void
    {
        $this->actingAs($this->administrativo());
        $datos = ['confirmacion' => '1', 'version_importe' => str_repeat('a', 64)];
        $enCurso = $this->atencion(['estado' => 'EN_ATENCION'], ['estado' => 'EN_CURSO', 'fecha_hora_fin' => null]);
        $citaIncompatible = $this->atencion(['estado' => 'CANCELADA']);
        foreach ([$enCurso, $citaIncompatible] as $atencion) {
            $this->postJson($this->url($atencion), $datos)->assertUnprocessable()->assertJsonValidationErrors('atencion');
        }
        $inconsistente = $this->atencion();
        $inconsistente->cita()->update(['paciente_id' => Paciente::factory()->create()->id]);
        $this->get($this->url($inconsistente))->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($this->url($inconsistente), $datos)->assertNotFound();
        $this->get($this->url().'/999999999999999999999')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->sinEscrituras();
    }

    public static function basesInvalidas(): array
    {
        return ['nulo' => [null], 'cero' => ['0.00'], 'negativo' => ['-5.25'], 'fuera de rango' => ['10000000000.00']];
    }

    #[DataProvider('basesInvalidas')]
    public function test_revalida_importe_invalido_en_la_escritura(?string $importe): void
    {
        $this->actingAs($this->administrativo());
        $atencion = $this->atencion();
        $datos = $this->confirmacion($atencion);
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            $atencion->cita()->update(['valor_base' => $importe]);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->postJson($this->url($atencion), $datos)->assertUnprocessable()
            ->assertJsonValidationErrors('version_importe')->assertJsonFragment(['message' => 'La cita debe tener un valor base positivo válido.']);
        $this->sinEscrituras();
    }

    public function test_fallo_de_auditoria_revierte_liquidacion_y_no_expone_sql(): void
    {
        $this->actingAs($this->administrativo());
        $atencion = $this->atencion();
        $datos = $this->confirmacion($atencion);
        Log::spy();
        Auditoria::creating(fn () => DB::select('SELECT contenido FROM tabla_auditoria_no_disponible'));
        $this->postJson($this->url($atencion), $datos)->assertServiceUnavailable()->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('tabla_auditoria_no_disponible')->assertDontSee('DATO-CLINICO-QA-RESERVADO');
        Log::shouldHaveReceived('error')->once()->with('Error de base de datos en RF-033.', [
            'modulo' => 'RF-033', 'operacion' => 'pagos-particulares.liquidaciones.store',
        ]);
        $this->sinEscrituras();
    }

    public static function importesSqlite(): array
    {
        return [
            'milésima mayor a un peso' => ['1.001', 'PARTICULAR', false],
            'milésima menor a un centavo' => ['0.001', 'PARTICULAR', false],
            'centavos válidos' => ['1.01', 'PARTICULAR', true],
            'pesos válidos' => ['80000.00', 'PARTICULAR', true],
            'máximo exacto' => ['9999999999.99', 'PARTICULAR', true],
            'fuera de rango' => ['10000000000.00', 'PARTICULAR', false],
            'cuota cero compatible' => ['0.00', 'EPS', true],
        ];
    }

    #[DataProvider('importesSqlite')]
    public function test_sqlite_impone_escala_y_rango_en_insert_y_update(string $importe, string $modalidad, bool $valido): void
    {
        $actor = $this->administrativo();
        $nueva = $this->atencion();
        $existente = $this->atencion();
        $fila = [
            'modalidad' => $modalidad, 'fecha_liquidacion' => now(), 'creado_por' => $actor->id,
        ];
        $id = DB::table('liquidaciones')->insertGetId($fila + ['atencion_medica_id' => $existente->id, 'valor_total' => '1.00']);

        foreach ([
            fn () => DB::table('liquidaciones')->insert($fila + ['atencion_medica_id' => $nueva->id, 'valor_total' => $importe]),
            fn () => DB::table('liquidaciones')->where('id', $id)->update(['valor_total' => $importe]),
        ] as $escribir) {
            try {
                $escribir();
                $this->assertTrue($valido, 'SQLite permitió un importe fuera del contrato.');
            } catch (QueryException $error) {
                $this->assertFalse($valido, 'SQLite rechazó un importe válido.');
                $this->assertSame('23000', (string) $error->getCode());
            }
        }
        $this->assertDatabaseCount('liquidaciones', $valido ? 2 : 1);
        $this->assertSame($valido ? $importe : '1.00', Liquidacion::query()->findOrFail($id)->valor_total);
    }

    public function test_seeder_es_incremental_e_idempotente_para_los_tres_roles(): void
    {
        $this->seed(SecuritySeeder::class);
        $permisos = Permiso::query()->count();
        $asignaciones = DB::table('permiso_rol')->count();
        $this->seed(LiquidacionParticularPermissionsSeeder::class);
        $this->seed(LiquidacionParticularPermissionsSeeder::class);
        $permiso = Permiso::query()->where('codigo', 'RF-033')->sole();
        $this->assertSame(['Administrador', 'Administrativo', 'Médico'], $permiso->roles()->orderBy('nombre')->pluck('nombre')->all());
        $this->assertDatabaseCount('permisos', $permisos + 1);
        $this->assertDatabaseCount('permiso_rol', $asignaciones + 3);
    }

    private function sinEscrituras(): void
    {
        $this->assertDatabaseCount('liquidaciones', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }
}
