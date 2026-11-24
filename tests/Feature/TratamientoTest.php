<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Medico;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Tratamiento;
use App\Models\User;
use Database\Seeders\TratamientoPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TratamientoTest extends TestCase
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

    private function usuario(string $rol, ?string $codigo = 'RF-056'): User
    {
        $rol = Rol::query()->firstOrCreate(['nombre' => $rol]);
        if ($codigo !== null) {
            $rol->permisos()->syncWithoutDetaching([Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $codigo])->id]);
        }

        return User::factory()->for($rol)->create();
    }

    private function medico(?User $actor = null): Medico
    {
        return ($actor ?? $this->usuario('Médico'))->medico()->create([
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

    private function url(string $accion, mixed $parametros = []): string
    {
        return route('atenciones.clinica.tratamientos.'.$accion, $parametros);
    }

    private function alta(AtencionMedica $atencion, string $texto = 'Texto clínico inicial'): Tratamiento
    {
        $this->actingAs($atencion->medico->user)->post($this->url('store', $atencion), [
            'descripcion_tratamiento' => $texto, 'clave_envio' => (string) Str::uuid(),
        ])->assertRedirect($this->url('index', $atencion))
            ->assertHeader('Cache-Control', 'no-store, private')->assertSessionHasNoErrors();

        return $atencion->tratamientos()->sole();
    }

    public function test_ca05601_atencion_historia_y_permiso_independiente(): void
    {
        $medico = $this->medico();
        $atencion = $this->atencion($medico);
        $registro = $this->alta($atencion, '<script>Ejemplo ficticio</script>');
        $this->assertSame($atencion->historia_clinica_id, $registro->atencionMedica->historiaClinica->id);
        $this->assertSame($medico->id, $registro->atencionMedica->medico_id);
        $this->assertNotNull($registro->fecha_registro);
        $this->get($this->url('atenciones'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Tratamientos')->assertSee($this->url('index', $atencion));
        $respuesta = $this->get($this->url('index', $atencion))->assertOk()
            ->assertSee('<script>Ejemplo ficticio</script>')->assertDontSee('<script>Ejemplo ficticio</script>', false);
        $this->assertTrue($respuesta->headers->hasCacheControlDirective('no-store'));
        $this->get(route('atenciones.clinica.show', $atencion))->assertForbidden();
        $this->assertDatabaseHas('auditorias', ['accion' => 'TRATAMIENTO_REGISTRADO', 'registro_id' => $registro->id, 'user_id' => $medico->user_id]);
        $this->assertStringNotContainsString('Ejemplo ficticio', Auditoria::query()->sole()->descripcion);
    }

    public function test_ca05602_tras_el_cierre_solo_se_puede_leer(): void
    {
        $medico = $this->medico();
        $atencion = $this->atencion($medico);
        $registro = $this->alta($atencion);
        $atencion->forceFill(['estado' => 'FINALIZADA', 'fecha_hora_fin' => now()])->save();
        $atencion->cita->forceFill(['estado' => 'ATENDIDA'])->save();

        $this->get($this->url('index', $atencion))->assertOk()->assertSee($registro->descripcion)->assertDontSee('Guardar corrección');
        $this->patchJson($this->url('update', [$atencion, $registro]), [
            'descripcion_tratamiento' => 'Cambio fuera de tiempo', 'version' => $registro->versionClinica(),
        ])->assertUnprocessable()->assertJsonValidationErrors('atencion');
        $this->postJson($this->url('store', $atencion), [
            'descripcion_tratamiento' => 'Alta fuera de tiempo', 'clave_envio' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('atencion');
        $this->assertSame('Texto clínico inicial', $registro->refresh()->descripcion);
        $this->assertDatabaseCount('tratamientos', 1);
        $this->assertDatabaseCount('revisiones_tratamientos', 0);
        $this->assertDatabaseCount('auditorias', 1);
    }

    public function test_ca05603_corrige_por_id_y_conserva_version_previa(): void
    {
        $atencion = $this->atencion($this->medico());
        $registro = $this->alta($atencion);
        $original = $registro->getRawOriginal('fecha_registro');
        $version = $registro->versionClinica();
        $url = $this->url('update', [$atencion, $registro]);

        $this->patch($url, ['descripcion_tratamiento' => '  Texto clínico inicial  ', 'version' => $version])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('revisiones_tratamientos', 0);
        $this->assertDatabaseCount('auditorias', 1);
        $this->patch($url, ['descripcion_tratamiento' => 'Texto corregido', 'version' => $version])->assertSessionHasNoErrors();
        $revision = $registro->revisiones()->sole();
        $this->assertSame('Texto clínico inicial', $revision->descripcion_anterior);
        $this->assertSame($atencion->medico->user_id, $revision->autor->id);
        $this->assertNotNull($revision->fecha_correccion);
        $this->assertSame($original, $registro->refresh()->getRawOriginal('fecha_registro'));
        $this->assertSame('Texto corregido', $registro->descripcion);
        $this->patchJson($url, ['descripcion_tratamiento' => 'Texto obsoleto', 'version' => $version])
            ->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private')->assertJsonValidationErrors('version');
        $this->patch($url, ['descripcion_tratamiento' => 'Texto obsoleto', 'version' => $version])
            ->assertRedirect($this->url('index', $atencion))->assertHeader('Cache-Control', 'no-store, private')
            ->assertSessionHasErrors('version')->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('tratamientos', 1);
        $this->assertDatabaseCount('revisiones_tratamientos', 1);
        $this->assertDatabaseHas('auditorias', ['accion' => 'TRATAMIENTO_CORREGIDO', 'registro_id' => $registro->id]);
        $this->assertSame('Texto corregido', $registro->refresh()->descripcion);
    }

    public static function actoresNoAutorizados(): array
    {
        return [
            'anónimo' => ['anonimo', 401],
            'médico ajeno' => ['ajeno', 404],
            'administrativo' => ['administrativo', 403],
            'administrativo titular con perfil y ambos permisos' => ['administrativo titular', 403],
            'administrador sin perfil' => ['administrador', 403],
            'titular con solo permiso de cierre' => ['titular sin permiso', 403],
            'titular inactivo' => ['inactivo', 302],
        ];
    }

    #[DataProvider('actoresNoAutorizados')]
    public function test_ca05604_rechaza_acceso_directo_sin_permiso_o_titularidad(string $tipo, int $estado): void
    {
        $medico = $tipo === 'administrativo titular'
            ? $this->medico($this->usuario('Administrativo'))
            : $this->medico();
        $atencion = $this->atencion($medico);
        $registro = $atencion->tratamientos()->create([
            'descripcion' => 'Texto protegido', 'fecha_registro' => now(),
            'clave_envio' => (string) Str::uuid(), 'huella_envio' => str_repeat('a', 64),
        ]);

        $actor = match ($tipo) {
            'ajeno' => $this->medico()->user,
            'administrativo' => $this->usuario('Administrativo', null),
            'administrador' => $this->usuario('Administrador'),
            'titular sin permiso', 'administrativo titular' => $medico->user,
            'inactivo' => $medico->user->forceFill(['estado' => 'INACTIVO']),
            default => null,
        };
        if ($tipo === 'inactivo') {
            $actor->save();
        }
        if ($tipo === 'titular sin permiso') {
            $actor->forceFill(['cargo' => 'Médico'])->save();
            $otroPermiso = Permiso::query()->create(['codigo' => 'RF-031', 'descripcion' => 'Cierre']);
            $actor->rol->permisos()->sync([$otroPermiso->id]);
        }
        if ($tipo === 'administrativo titular') {
            $permisoFormula = Permiso::query()->create(['codigo' => 'RF-057', 'descripcion' => 'Fórmula']);
            $actor->rol->permisos()->syncWithoutDetaching([$permisoFormula->id]);
        }
        if ($actor) {
            $this->actingAs($actor);
        }
        $respuesta = $this->getJson($this->url('index', $atencion))->assertStatus($estado)->assertDontSee('Texto protegido');
        $respuesta->assertHeader('Cache-Control', 'no-store, private');
        if ($estado === 403) {
            $this->get($this->url('atenciones'))->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        }
        if ($tipo === 'inactivo') {
            $respuesta->assertRedirect(route('login'));
            $this->assertGuest();
            $this->actingAs($actor);
        }
        $this->postJson($this->url('store', $atencion), [
            'descripcion_tratamiento' => 'Otra descripción', 'clave_envio' => (string) Str::uuid(),
        ])->assertStatus($estado)->assertHeader('Cache-Control', 'no-store, private');
        if ($tipo === 'inactivo') {
            $this->assertGuest();
            $this->actingAs($actor);
        }
        $this->patchJson($this->url('update', [$atencion, $registro]), [
            'descripcion_tratamiento' => 'Intento de corrección', 'version' => $registro->versionClinica(),
        ])->assertStatus($estado)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('tratamientos', 1);
        $this->assertDatabaseCount('revisiones_tratamientos', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function textosInvalidos(): array
    {
        return ['espacios Unicode' => [" \u{00a0} "], 'arreglo' => [['invalido']], 'control nulo' => ["uno\0dos"], 'fuera de capacidad' => [null]];
    }

    #[DataProvider('textosInvalidos')]
    public function test_ca05604_rechaza_textos_invalidos(mixed $texto): void
    {
        $atencion = $this->atencion($this->medico());
        $this->actingAs($atencion->medico->user)->postJson($this->url('store', $atencion), [
            'descripcion_tratamiento' => $texto ?? str_repeat('á', 32768), 'clave_envio' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('descripcion_tratamiento');
        $this->assertDatabaseCount('tratamientos', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_clave_de_envio_distingue_reintento_y_alta_legitima(): void
    {
        $atencion = $this->atencion($this->medico());
        $url = $this->url('store', $atencion);
        $datos = ['descripcion_tratamiento' => 'Tratamiento repetible', 'clave_envio' => (string) Str::uuid()];
        $this->actingAs($atencion->medico->user);
        $this->post($url, $datos)->assertSessionHasNoErrors();
        $this->post($url, $datos)->assertSessionHas('status', 'El tratamiento ya estaba registrado.');
        $this->postJson($url, [...$datos, 'descripcion_tratamiento' => 'Contenido diferente'])->assertUnprocessable()->assertJsonValidationErrors('clave_envio');
        $this->post($url, [...$datos, 'clave_envio' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tratamientos', 2);
        $this->assertDatabaseCount('auditorias', 2);
    }

    public function test_aisla_tratamientos_y_vinculos_de_pacientes(): void
    {
        $medico = $this->medico();
        $primera = $this->atencion($medico);
        $segunda = $this->atencion($medico);
        $registro = $this->alta($primera);
        $this->get($this->url('index', $segunda))->assertOk()->assertDontSee($registro->descripcion);
        $this->patchJson($this->url('update', [$segunda, $registro]), [
            'descripcion_tratamiento' => 'Intento cruzado', 'version' => $registro->versionClinica(),
        ])->assertNotFound();
        $segunda->forceFill(['historia_clinica_id' => $primera->historia_clinica_id])->save();
        $this->get($this->url('index', $segunda))->assertNotFound();
        $this->get($this->url('atenciones'))->assertOk()->assertDontSee($this->url('index', $segunda));
        $this->postJson($this->url('store', $segunda), [
            'descripcion_tratamiento' => 'Alteración cruzada', 'clave_envio' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertDatabaseCount('tratamientos', 1);
        $this->assertDatabaseCount('revisiones_tratamientos', 0);
    }

    public function test_fallo_de_auditoria_revierte_alta_y_correccion_y_no_filtra_datos(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        $atencion = $this->atencion($this->medico());
        $registro = $this->alta($atencion);
        Schema::drop('auditorias');
        $datos = ['descripcion_tratamiento' => 'Texto sensible ficticio de prueba'];
        $this->postJson($this->url('store', $atencion), [...$datos, 'clave_envio' => (string) Str::uuid()])
            ->assertStatus(503)->assertDontSee($datos['descripcion_tratamiento']);
        $this->patchJson($this->url('update', [$atencion, $registro]), [...$datos, 'version' => $registro->versionClinica()])
            ->assertStatus(503)->assertDontSee($datos['descripcion_tratamiento']);
        $this->assertDatabaseCount('tratamientos', 1);
        $this->assertDatabaseCount('revisiones_tratamientos', 0);
        $this->assertSame('Texto clínico inicial', $registro->refresh()->descripcion);
        Log::shouldHaveReceived('error')->with('Error de base de datos en AtencionMedica.', [
            'modulo' => 'AtencionMedica', 'operacion' => 'atenciones.clinica.tratamientos.store',
        ])->once();
        Log::shouldHaveReceived('error')->with('Error de base de datos en AtencionMedica.', [
            'modulo' => 'AtencionMedica', 'operacion' => 'atenciones.clinica.tratamientos.update',
        ])->once();
    }

    public function test_no_conserva_texto_clinico_en_la_sesion_al_fallar_el_formulario(): void
    {
        Log::spy();
        Exceptions::fake();
        $atencion = $this->atencion($this->medico());
        $marcador = 'QA_CLINICAL_REJECTED_MARKER_056';
        $datos = [
            'descripcion_tratamiento' => 'Texto clínico a proteger',
            'clave_envio' => (string) Str::uuid(),
            'campo_ajeno' => $marcador,
        ];
        $this->actingAs($atencion->medico->user)
            ->withSession(['_old_input' => ['campo_ajeno' => $marcador]])
            ->post($this->url('store', $atencion), $datos)
            ->assertRedirect($this->url('index', $atencion))->assertHeader('Cache-Control', 'no-store, private')
            ->assertSessionHasErrors('solicitud')->assertSessionMissing('_old_input')->assertDontSee($marcador);

        $this->withSession(['_old_input' => ['campo_ajeno' => $marcador]])
            ->postJson($this->url('store', $atencion), $datos)->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonValidationErrors('solicitud')->assertSessionMissing('_old_input')
            ->assertDontSee($marcador)->assertDontSee($datos['descripcion_tratamiento']);
        $this->assertStringNotContainsString($marcador, serialize(session()->all()));
        $this->assertStringNotContainsString($datos['descripcion_tratamiento'], serialize(session()->all()));
        Exceptions::assertNothingReported();
        Log::shouldNotHaveReceived('error');
        $this->assertDatabaseCount('tratamientos', 0);
    }

    public function test_cabecera_del_invitado_y_del_id_inexistente_es_exclusiva_de_la_ruta_clinica(): void
    {
        $atencion = $this->atencion($this->medico());
        $this->get($this->url('index', $atencion))->assertRedirect(route('login'))
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/dashboard')->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-cache, private');
        $this->actingAs($atencion->medico->user)->get($this->url('index', 999999))->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function rolesClinicos(): array
    {
        return ['médico' => ['Médico'], 'administrador' => ['Administrador']];
    }

    #[DataProvider('rolesClinicos')]
    public function test_titular_con_rol_clinico_registra_corrige_y_consulta_tratamientos(string $rol): void
    {
        $atencion = $this->atencion($this->medico($this->usuario($rol)));
        $permiso = Permiso::query()->create(['codigo' => 'RF-057', 'descripcion' => 'Fórmula']);
        $atencion->medico->user->rol->permisos()->syncWithoutDetaching([$permiso->id]);
        $registro = $this->alta($atencion);
        $this->patch($this->url('update', [$atencion, $registro]), [
            'descripcion_tratamiento' => 'Corrección ficticia', 'version' => $registro->versionClinica(),
        ])->assertRedirect($this->url('index', $atencion))->assertHeader('Cache-Control', 'no-store, private');
        $this->get($this->url('index', $atencion))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Corrección ficticia');
        $this->assertDatabaseCount('revisiones_tratamientos', 1);
    }

    public function test_seeder_incremental_idempotente_y_asignaciones_independientes(): void
    {
        foreach (['Administrador', 'Administrativo', 'Médico'] as $rol) {
            Rol::query()->firstOrCreate(['nombre' => $rol]);
        }
        $this->usuario('Administrativo', 'RF-010');
        $previos = DB::table('permiso_rol')->count();
        $this->seed(TratamientoPermissionsSeeder::class);
        $this->seed(TratamientoPermissionsSeeder::class);
        $this->assertSame($previos + 2, DB::table('permiso_rol')->count());
        $this->assertSame(1, Permiso::query()->where('codigo', 'RF-056')->count());
        $this->assertSame(['Administrador', 'Médico'], Rol::query()->whereHas('permisos', fn ($query) => $query->where('codigo', 'RF-056'))->orderBy('nombre')->pluck('nombre')->all());
        $this->assertDatabaseHas('permisos', ['codigo' => 'RF-010']);
    }
}
