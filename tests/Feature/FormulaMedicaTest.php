<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Medico;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\FormulaMedicaPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormulaMedicaTest extends TestCase
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

    private function usuario(string $nombreRol = 'Médico', ?string $permiso = 'RF-057'): User
    {
        $rol = Rol::query()->firstOrCreate(['nombre' => $nombreRol]);
        if ($permiso !== null) {
            $rol->permisos()->syncWithoutDetaching([Permiso::query()->firstOrCreate(['codigo' => $permiso], ['descripcion' => $permiso])->id]);
        }

        return User::factory()->for($rol)->create();
    }

    private function medico(?User $actor = null): Medico
    {
        return ($actor ?? $this->usuario())->medico()->create([
            'registro_profesional' => fake()->unique()->numerify('DEMO-########'), 'especialidad' => 'Medicina general',
        ]);
    }

    private function atencion(Medico $medico): AtencionMedica
    {
        $bloque = DisponibilidadMedica::factory()->for($medico)->create([
            'fecha' => now()->addDays($medico->disponibilidades()->count())->toDateString(),
        ]);
        $cita = Cita::factory()->for($bloque, 'disponibilidad')->for($medico->user, 'creador')->create(['estado' => 'EN_ATENCION']);

        return AtencionMedica::factory()->for($cita)->create()->refresh();
    }

    private function datos(): array
    {
        return [
            'clave_envio' => (string) Str::uuid(),
            'observacion_formula' => 'Observación ficticia',
            'detalles_formula' => [
                ['medicamento' => '<script>Medicamento ficticio A</script>', 'dosis' => 'Dosis ficticia A', 'frecuencia' => 'Frecuencia ficticia'],
                ['medicamento' => 'Medicamento ficticio B', 'dosis' => 'Dosis ficticia B', 'duracion' => 'Duración ficticia', 'indicaciones' => 'Indicación ficticia'],
            ],
        ];
    }

    private function url(string $accion, ?AtencionMedica $atencion = null): string
    {
        return route('atenciones.clinica.formulas.'.$accion, $atencion ?? []);
    }

    private function registrar(AtencionMedica $atencion): void
    {
        $this->actingAs($atencion->medico->user)->post($this->url('store', $atencion), $this->datos())
            ->assertRedirect($this->url('index', $atencion))
            ->assertHeader('Cache-Control', 'no-store, private')->assertSessionHasNoErrors();
    }

    public function test_ca05701_persiste_cabecera_dos_detalles_en_orden_y_auditoria(): void
    {
        $this->freezeSecond();
        $atencion = $this->atencion($this->medico());
        $this->registrar($atencion);

        $formula = $atencion->formulasMedicas()->sole();
        $this->assertSame($atencion->historia_clinica_id, $formula->atencionMedica->historiaClinica->id);
        $this->assertSame(now()->toDateTimeString(), $formula->fecha_emision->toDateTimeString());
        $this->assertSame('Observación ficticia', $formula->observacion);
        $detalles = $formula->detalles()->get();
        $this->assertSame([1, 2], $detalles->pluck('posicion')->all());
        $this->assertSame(['Dosis ficticia A', 'Dosis ficticia B'], $detalles->pluck('dosis')->all());
        $this->assertSame(['Frecuencia ficticia', null], $detalles->pluck('frecuencia')->all());
        $this->assertSame([null, 'Duración ficticia'], $detalles->pluck('duracion')->all());
        $this->assertSame([null, 'Indicación ficticia'], $detalles->pluck('indicaciones')->all());
        $this->assertDatabaseCount('formulas_medicas', 1);
        $this->assertDatabaseCount('detalles_formula', 2);
        $this->assertDatabaseCount('auditorias', 1);
        $auditoria = Auditoria::query()->sole();
        $this->assertSame($atencion->medico->user_id, $auditoria->user_id);
        $this->assertSame($formula->id, $auditoria->registro_id);
        $this->assertSame('FormulaMedica', $auditoria->entidad);
        $this->assertSame('FORMULA_MEDICA_REGISTRADA', $auditoria->accion);
        $this->assertSame('Atención #'.$atencion->id.'.', $auditoria->descripcion);

        $vista = $this->get($this->url('index', $atencion))->assertOk()
            ->assertSee('Observación ficticia')->assertSee('Indicación ficticia')
            ->assertSeeInOrder(['<script>Medicamento ficticio A</script>', 'Medicamento ficticio B'])
            ->assertDontSee('<script>Medicamento ficticio A</script>', false);
        $this->assertTrue($vista->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($vista->headers->hasCacheControlDirective('private'));
        $this->get($this->url('atenciones'))->assertOk()->assertSee($this->url('index', $atencion));
        $this->get(route('atenciones.clinica.show', $atencion))->assertForbidden();
        $this->get(route('atenciones.clinica.tratamientos.index', $atencion))->assertForbidden();
    }

    public static function datosInvalidos(): array
    {
        return [
            'CA-057-02 lista vacía' => ['detalles_formula', [], 'detalles_formula'],
            'CA-057-03 segundo detalle sin dosis' => ['detalles_formula', [['medicamento' => 'A', 'dosis' => 'A'], ['medicamento' => 'B']], 'detalles_formula.1.dosis'],
            'CA-057-04 medicamento vacío' => ['detalles_formula', [['medicamento' => ' ', 'dosis' => 'A']], 'detalles_formula.0.medicamento'],
            'CA-057-04 lista con clave ajena' => ['detalles_formula', ['dato reservado' => ['medicamento' => 'A', 'dosis' => 'A']], 'detalles_formula'],
            'CA-057-04 detalle con clave ajena' => ['detalles_formula', [['medicamento' => 'A', 'dosis' => 'A', 'id' => 1]], 'detalles_formula.0'],
            'CA-057-04 observación inválida' => ['observacion_formula', ['texto'], 'observacion_formula'],
            'CA-057-04 texto inválido' => ['observacion_formula', "Observación\0incompleta", 'observacion_formula'],
            'CA-057-04 historia enviada por cliente' => ['historia_clinica_id', 1, 'solicitud'],
        ];
    }

    #[DataProvider('datosInvalidos')]
    public function test_rechaza_formulas_invalidas_sin_registros_parciales(string $campo, mixed $valor, string $error): void
    {
        $atencion = $this->atencion($this->medico());
        $this->actingAs($atencion->medico->user)->postJson($this->url('store', $atencion), [...$this->datos(), $campo => $valor])
            ->assertUnprocessable()->assertJsonValidationErrors($error)->assertDontSee('dato reservado');
        $this->assertSinEscrituras();
    }

    public static function actoresNoAutorizados(): array
    {
        return [
            'sin sesión' => ['anonimo', 401],
            'médico ajeno' => ['ajeno', 404],
            'administrativo' => ['administrativo', 403],
            'administrativo titular con perfil y ambos permisos' => ['administrativo titular', 403],
            'administrador sin perfil habilitado' => ['administrador', 403],
            'titular con RF-056' => ['tratamientos', 403],
            'titular inactivo' => ['inactivo', 302],
        ];
    }

    #[DataProvider('actoresNoAutorizados')]
    public function test_ca05704_bloquea_lectura_y_escritura_sin_autorizacion(string $tipo, int $estado): void
    {
        $titular = match ($tipo) {
            'tratamientos' => $this->medico($this->usuario('Médico', 'RF-056')),
            'administrativo titular' => $this->medico($this->usuario('Administrativo')),
            default => $this->medico(),
        };
        $atencion = $this->atencion($titular);
        $actor = match ($tipo) {
            'ajeno' => $this->medico()->user,
            'administrativo' => $this->usuario('Administrativo', null),
            'administrador' => $this->usuario('Administrador'),
            'tratamientos', 'inactivo', 'administrativo titular' => $titular->user,
            default => null,
        };
        if ($tipo === 'inactivo') {
            $actor->forceFill(['estado' => 'INACTIVO'])->save();
        }
        if ($tipo === 'tratamientos') {
            $actor->forceFill(['cargo' => 'Médico'])->save();
        }
        if ($tipo === 'administrativo titular') {
            $permiso = Permiso::query()->create(['codigo' => 'RF-056', 'descripcion' => 'Tratamientos']);
            $actor->rol->permisos()->syncWithoutDetaching([$permiso->id]);
        }
        if ($actor) {
            $this->actingAs($actor);
        }
        $vista = $this->getJson($this->url('index', $atencion))->assertStatus($estado)->assertHeader('Cache-Control', 'no-store, private');
        if ($estado === 403) {
            $this->get($this->url('atenciones'))->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        }
        if ($tipo === 'inactivo') {
            $vista->assertRedirect(route('login'));
            $this->actingAs($actor);
        }
        $this->postJson($this->url('store', $atencion), $this->datos())->assertStatus($estado)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSinEscrituras();
    }

    public function test_reenvio_clave_alterada_y_dos_altas_legitimas(): void
    {
        $atencion = $this->atencion($this->medico());
        $this->actingAs($atencion->medico->user);
        $datos = $this->datos();
        $url = $this->url('store', $atencion);
        $this->post($url, $datos)->assertSessionHasNoErrors();
        $datos['detalles_formula'][0] = array_reverse($datos['detalles_formula'][0], preserve_keys: true);
        $datos['detalles_formula'][0]['duracion'] = '';
        $this->post($url, $datos)->assertSessionHas('status', 'La fórmula ya estaba registrada.');
        $this->assertDatabaseCount('auditorias', 1);
        $this->postJson($url, [...$datos, 'detalles_formula' => array_reverse($datos['detalles_formula'])])
            ->assertUnprocessable()->assertJsonValidationErrors('clave_envio');
        $this->assertDatabaseCount('formulas_medicas', 1);
        $this->assertDatabaseCount('detalles_formula', 2);
        $this->post($url, [...$datos, 'clave_envio' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('formulas_medicas', 2);
        $this->assertDatabaseCount('detalles_formula', 4);
        $this->assertDatabaseCount('auditorias', 2);
    }

    public function test_atencion_finalizada_muestra_formula_y_rechaza_post_sin_modificar_estados(): void
    {
        $atencion = $this->atencion($this->medico());
        $this->registrar($atencion);
        $atencion->forceFill(['estado' => 'FINALIZADA', 'fecha_hora_fin' => now()])->save();
        $atencion->cita->forceFill(['estado' => 'ATENDIDA'])->save();

        $this->get($this->url('index', $atencion))->assertOk()->assertSee('solo lectura')
            ->assertSee('Dosis ficticia A')->assertDontSee('Registrar fórmula médica');
        $this->postJson($this->url('store', $atencion), $this->datos())->assertUnprocessable()->assertJsonValidationErrors('atencion');
        $this->assertDatabaseCount('formulas_medicas', 1);
        $this->assertDatabaseCount('detalles_formula', 2);
        $this->assertDatabaseCount('auditorias', 1);
        $this->assertSame('FINALIZADA', $atencion->refresh()->estado);
        $this->assertSame('ATENDIDA', $atencion->cita->estado);
    }

    public function test_aisla_atenciones_pacientes_y_formulas_de_medicos_ajenos(): void
    {
        $titular = $this->medico();
        $primera = $this->atencion($titular);
        $segunda = $this->atencion($titular);
        $ajeno = $this->medico();
        $this->registrar($primera);

        $this->get($this->url('index', $segunda))->assertOk()->assertDontSee('Dosis ficticia A');
        $this->actingAs($ajeno->user)->get($this->url('index', $primera))->assertNotFound()->assertDontSee('Dosis ficticia A');
        $this->get($this->url('atenciones'))->assertOk()->assertDontSee($this->url('index', $primera));
        $this->actingAs($titular->user);
        $segunda->forceFill(['historia_clinica_id' => $primera->historia_clinica_id])->save();
        $this->get($this->url('index', $segunda))->assertNotFound();
        $this->get($this->url('atenciones'))->assertOk()->assertDontSee($this->url('index', $segunda));
        $this->postJson($this->url('store', $segunda), $this->datos())->assertNotFound();
        $this->assertDatabaseCount('formulas_medicas', 1);
    }

    public static function fallos(): array
    {
        return ['segundo detalle' => ['detalle'], 'auditoría' => ['auditoria']];
    }

    #[DataProvider('fallos')]
    public function test_rollback_total_y_excepcion_sin_texto_clinico(string $fallo): void
    {
        config(['app.debug' => true]);
        Log::spy();
        $atencion = $this->atencion($this->medico());
        if ($fallo === 'detalle') {
            DB::unprepared("CREATE TEMP TRIGGER falla_detalle BEFORE INSERT ON detalles_formula WHEN NEW.posicion = 2 BEGIN SELECT RAISE(ABORT, 'Fallo de QA'); END");
        } else {
            Schema::drop('auditorias');
        }
        $respuesta = $this->actingAs($atencion->medico->user)->postJson($this->url('store', $atencion), $this->datos())
            ->assertServiceUnavailable()->assertDontSee('Dosis ficticia B')->assertDontSee('Observación ficticia');
        $this->assertTrue($respuesta->headers->hasCacheControlDirective('no-store'));
        $this->assertDatabaseCount('formulas_medicas', 0);
        $this->assertDatabaseCount('detalles_formula', 0);
        if ($fallo === 'detalle') {
            $this->assertDatabaseCount('auditorias', 0);
        }
        Log::shouldHaveReceived('error')->with('Error de base de datos en AtencionMedica.', [
            'modulo' => 'AtencionMedica', 'operacion' => 'atenciones.clinica.formulas.store',
        ])->once();
    }

    public function test_validacion_web_no_guarda_datos_clinicos_en_sesion(): void
    {
        $atencion = $this->atencion($this->medico());
        $this->actingAs($atencion->medico->user)->post($this->url('store', $atencion), [
            ...$this->datos(), 'campo_ajeno' => 'Texto clínico ficticio en campo no permitido',
        ])->assertRedirect($this->url('index', $atencion))->assertHeader('Cache-Control', 'no-store, private')
            ->assertSessionHasErrors('solicitud')->assertSessionMissing('_old_input');
        $this->assertSinEscrituras();
    }

    public function test_cabeceras_del_invitado_id_inexistente_y_validacion_json(): void
    {
        $atencion = $this->atencion($this->medico());
        $this->get($this->url('index', $atencion))->assertRedirect(route('login'))
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($atencion->medico->user)
            ->get(route('atenciones.clinica.formulas.index', 999999))->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($this->url('store', $atencion), [])->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonValidationErrors('detalles_formula');
        $this->assertSinEscrituras();
    }

    public static function rolesClinicos(): array
    {
        return ['médico' => ['Médico'], 'administrador' => ['Administrador']];
    }

    #[DataProvider('rolesClinicos')]
    public function test_titular_con_rol_clinico_registra_y_consulta_formulas(string $rol): void
    {
        $atencion = $this->atencion($this->medico($this->usuario($rol)));
        $permiso = Permiso::query()->create(['codigo' => 'RF-056', 'descripcion' => 'Tratamientos']);
        $atencion->medico->user->rol->permisos()->syncWithoutDetaching([$permiso->id]);
        $this->registrar($atencion);
        $this->get($this->url('index', $atencion))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Dosis ficticia A');
        $this->assertDatabaseCount('detalles_formula', 2);
    }

    public function test_seeder_independiente_y_repetible(): void
    {
        $this->usuario('Médico', 'RF-056');
        foreach (['Administrador', 'Administrativo'] as $rol) {
            Rol::query()->create(['nombre' => $rol]);
        }
        $previas = DB::table('permiso_rol')->count();
        $this->seed(FormulaMedicaPermissionsSeeder::class);
        $this->seed(FormulaMedicaPermissionsSeeder::class);
        $this->assertSame($previas + 2, DB::table('permiso_rol')->count());
        $this->assertSame(1, Permiso::query()->where('codigo', 'RF-057')->count());
        $this->assertSame(['Administrador', 'Médico'], Rol::query()->whereHas('permisos', fn ($q) => $q->where('codigo', 'RF-057'))->orderBy('nombre')->pluck('nombre')->all());
        $this->assertDatabaseHas('permisos', ['codigo' => 'RF-056']);
    }

    private function assertSinEscrituras(): void
    {
        $this->assertDatabaseCount('formulas_medicas', 0);
        $this->assertDatabaseCount('detalles_formula', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }
}
