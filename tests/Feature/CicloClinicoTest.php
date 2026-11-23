<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\CambioEstadoRemision;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\CicloClinicoPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CicloClinicoTest extends TestCase
{
    use RefreshDatabase;

    private Medico $medico;

    protected function beforeRefreshingDatabase(): void
    {
        $db = DB::connection();
        if (! $this->app->environment('testing') || $this->app->configurationIsCached()
            || $db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:' || $db->getConfig('url')) {
            throw new LogicException('La prueba requiere SQLite en memoria.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2030-05-02 13:00:00', 'UTC'));
        $this->medico = $this->medico(['RF-029', 'RF-030', 'RF-031']);
    }

    /** @param list<string> $permisos */
    private function usuario(array $permisos): User
    {
        $rol = Rol::query()->create(['nombre' => 'Rol ficticio '.fake()->unique()->uuid()]);
        foreach ($permisos as $codigo) {
            $rol->permisos()->attach(Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $codigo]));
        }

        return User::factory()->for($rol)->create();
    }

    /** @param list<string> $permisos */
    private function medico(array $permisos): Medico
    {
        return $this->usuario($permisos)->medico()->create([
            'registro_profesional' => fake()->unique()->numerify('DEMO-########'), 'especialidad' => 'Medicina general',
        ]);
    }

    private function atencion(?Medico $medico = null, ?RemisionEps $remision = null): AtencionMedica
    {
        $medico ??= $this->medico;
        $paciente = $remision?->paciente ?? Paciente::factory()->create();
        $bloque = DisponibilidadMedica::factory()->for($medico)->create();
        $cita = Cita::factory()->for($paciente)->for($bloque, 'disponibilidad')->for($medico->user, 'creador')->create([
            'estado' => 'EN_ATENCION', 'remision_id' => $remision?->id, 'modalidad' => $remision ? 'EPS' : 'PARTICULAR',
            'valor_base' => $remision ? null : '50000.00',
        ]);

        return AtencionMedica::factory()->for($cita)->create()->refresh();
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function campos(): array
    {
        return [
            'CA-029 motivo' => ['motivo', 'motivo_consulta', 'RF-029', 'MOTIVO_CONSULTA_REGISTRADO'],
            'CA-030 diagnóstico' => ['diagnostico', 'diagnostico_principal', 'RF-030', 'DIAGNOSTICO_PRINCIPAL_REGISTRADO'],
        ];
    }

    #[DataProvider('campos')]
    public function test_registra_edita_y_consulta_solo_el_campo_de_la_atencion_propia(string $ruta, string $campo, string $permiso, string $accion): void
    {
        $medico = $this->medico([$permiso]);
        $atencion = $this->atencion($medico);
        $otra = $this->atencion();
        $otraAntes = $otra->getAttributes();
        $citaAntes = $atencion->cita->getAttributes();
        $historiaAntes = $atencion->historiaClinica->getAttributes();
        $pagina = route('atenciones.clinica.show', $atencion);
        $guardar = route('atenciones.clinica.'.$ruta, $atencion);
        $this->actingAs($medico->user)->get(route('atenciones.index'))->assertOk()->assertSee($pagina)->assertDontSee(route('atenciones.clinica.show', $otra));
        $detalle = $this->get($pagina)->assertOk()->assertSee($guardar)->assertSee($atencion->versionClinica($campo));
        $this->assertTrue($detalle->headers->hasCacheControlDirective('no-store'));
        $this->assertNull($atencion->$campo);
        $this->assertNull($atencion->fecha_hora_fin);
        $primeraVersion = $atencion->versionClinica($campo);
        $contenido = "<b>Contenido clínico ficticio</b>\nSegunda línea";
        $this->patch($guardar, [$campo => '  '.str_replace("\n", "\r\n", $contenido).'  ', 'version' => $primeraVersion])->assertRedirect($pagina)->assertSessionHasNoErrors();
        $this->get($pagina)->assertOk()->assertSee($contenido)->assertDontSee($contenido, false);
        $this->assertSame($contenido, $atencion->refresh()->$campo);
        $this->patch($guardar, [$campo => $contenido, 'version' => $primeraVersion])->assertSessionHasNoErrors();
        $audit = Auditoria::query()->sole();
        $this->assertSame($accion, $audit->accion);
        $this->assertSame('EXITO', $audit->resultado);
        $this->assertSame($atencion->id, $audit->registro_id);
        $this->assertSame($medico->user_id, $audit->user_id);
        $this->assertStringNotContainsString($contenido, $audit->descripcion);
        $this->patchJson($guardar, [$campo => 'Edición obsoleta', 'version' => $primeraVersion])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame($contenido, $atencion->refresh()->$campo);
        $this->patch($guardar, [$campo => 'Texto actualizado', 'version' => $atencion->versionClinica($campo)])->assertSessionHasNoErrors();
        $this->assertSame('Texto actualizado', $atencion->refresh()->$campo);
        $this->assertSame($otraAntes, $otra->refresh()->getAttributes());
        $this->assertSame($citaAntes, $atencion->cita->getAttributes());
        $this->assertSame($historiaAntes, $atencion->historiaClinica->getAttributes());
        $this->assertSame('Texto actualizado', $atencion->historiaClinica->atenciones()->sole()->$campo);
        $this->assertDatabaseCount('auditorias', 2);
        $this->assertDatabaseCount('historias_clinicas', 2);
        $this->assertDatabaseCount('atenciones_medicas', 2);
    }

    #[DataProvider('campos')]
    public function test_campos_invalidos_y_datos_protegidos_se_rechazan_sin_escrituras(string $ruta, string $campo, string $permiso, string $accion): void
    {
        $atencion = $this->atencion();
        $antes = $atencion->getAttributes();
        $guardar = route('atenciones.clinica.'.$ruta, $atencion);
        $version = $atencion->versionClinica($campo);
        $this->actingAs($this->medico->user);
        foreach ([null, '   ', ['arreglo'], str_repeat('é', 32768)] as $invalido) {
            $this->patchJson($guardar, [$campo => $invalido, 'version' => $version])->assertUnprocessable()->assertJsonValidationErrors($campo);
        }
        $extras = ['medico_id' => 9, 'historia_clinica_id' => 1, 'paciente_id' => 2, 'cita_id' => 9, 'estado' => 'FINALIZADA', 'fecha_hora_fin' => now()->toDateTimeString()];
        $this->patchJson($guardar, [$campo => 'Prueba clínica', 'version' => $version, ...$extras])
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($extras));
        $this->patch($guardar, [$campo => 'Prueba privada'])->assertSessionHasErrors('version')->assertSessionMissing('_old_input.'.$campo);
        $this->assertSame($antes, $atencion->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('campos')]
    public function test_exige_sesion_cuenta_activa_permiso_perfil_y_titularidad(string $ruta, string $campo, string $permiso, string $accion): void
    {
        $titular = $this->medico([$permiso]);
        $atencion = $this->atencion($titular);
        $atencion->forceFill([$campo => 'Contenido reservado'])->save();
        $antes = $atencion->refresh()->getAttributes();
        $pagina = route('atenciones.clinica.show', $atencion);
        $guardar = route('atenciones.clinica.'.$ruta, $atencion);
        $datos = [$campo => 'Cambio', 'version' => $atencion->versionClinica($campo)];
        $this->patchJson($guardar, $datos)->assertUnauthorized();
        $this->actingAs($this->medico([$permiso])->user)->get($pagina)->assertNotFound()->assertDontSee('Contenido reservado');
        $this->patchJson($guardar, $datos)->assertNotFound()->assertDontSee('Contenido reservado');
        $this->actingAs($this->usuario([$permiso]))->get($pagina)->assertForbidden();
        $this->patchJson($guardar, $datos)->assertForbidden();
        $titular->user->rol->permisos()->detach();
        $this->actingAs($titular->user)->patchJson($guardar, $datos)->assertForbidden();
        $titular->user->rol->permisos()->attach(Permiso::query()->where('codigo', $permiso)->sole());
        $titular->update(['especialidad' => '']);
        $this->patchJson($guardar, $datos)->assertForbidden();
        $titular->update(['especialidad' => 'Medicina general']);
        $titular->user->forceFill(['estado' => 'inactivo'])->save();
        $this->patch($guardar, $datos)->assertRedirect(route('login'));
        $this->assertSame($antes, $atencion->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('campos')]
    public function test_el_texto_finalizado_es_de_solo_lectura(string $ruta, string $campo, string $permiso, string $accion): void
    {
        $medico = $this->medico([$permiso]);
        $atencion = $this->atencion($medico);
        $atencion->forceFill(['estado' => 'FINALIZADA', $campo => 'Texto cerrado', 'fecha_hora_fin' => now()])->save();
        $atencion->cita->forceFill(['estado' => 'ATENDIDA'])->save();
        $guardar = route('atenciones.clinica.'.$ruta, $atencion);
        $this->actingAs($medico->user)->get(route('atenciones.clinica.show', $atencion))->assertOk()->assertSee('Texto cerrado')->assertDontSee($guardar);
        $this->patchJson($guardar, [$campo => 'Cambio', 'version' => $atencion->versionClinica($campo)])->assertUnprocessable()->assertJsonValidationErrors('atencion');
        $this->assertSame('Texto cerrado', $atencion->refresh()->$campo);
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('campos')]
    public function test_fallo_de_auditoria_revierte_el_texto(string $ruta, string $campo, string $permiso, string $accion): void
    {
        $atencion = $this->atencion();
        Auditoria::creating(static fn () => throw new RuntimeException('Fallo simulado.'));
        $this->actingAs($this->medico->user)->patchJson(route('atenciones.clinica.'.$ruta, $atencion), [
            $campo => 'Cambio', 'version' => $atencion->versionClinica($campo),
        ])->assertInternalServerError();
        $this->assertNull($atencion->refresh()->$campo);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_los_permisos_y_las_versiones_de_motivo_y_diagnostico_son_independientes(): void
    {
        $atencion = $this->atencion();
        $versionMotivo = $atencion->versionClinica('motivo_consulta');
        $versionDiagnostico = $atencion->versionClinica('diagnostico_principal');
        $motivo = route('atenciones.clinica.motivo', $atencion);
        $diagnostico = route('atenciones.clinica.diagnostico', $atencion);
        $this->actingAs($this->medico->user)->patch($motivo, ['motivo_consulta' => 'Primero', 'version' => $versionMotivo])->assertSessionHasNoErrors();
        $this->patch($diagnostico, ['diagnostico_principal' => 'Segundo', 'version' => $versionDiagnostico])->assertSessionHasNoErrors();
        $this->assertSame('Primero', $atencion->refresh()->motivo_consulta);
        $this->assertSame('Segundo', $atencion->diagnostico_principal);
        $this->assertDatabaseCount('auditorias', 2);
        $this->patch($motivo, ['motivo_consulta' => 'Primero editado', 'version' => $atencion->versionClinica('motivo_consulta')])->assertSessionHasNoErrors();
        $this->assertSame('Segundo', $atencion->refresh()->diagnostico_principal);
        $this->patch($diagnostico, ['diagnostico_principal' => 'Segundo editado', 'version' => $atencion->versionClinica('diagnostico_principal')])->assertSessionHasNoErrors();
        $this->assertSame('Primero editado', $atencion->refresh()->motivo_consulta);
        foreach (self::campos() as [$ruta, $campo, $permiso]) {
            $otroPermiso = $permiso === 'RF-029' ? 'RF-030' : 'RF-029';
            $titular = $this->medico([$otroPermiso]);
            $propia = $this->atencion($titular);
            $propia->forceFill([$campo => 'Texto de la otra operación'])->save();
            $url = route('atenciones.clinica.'.$ruta, $propia);
            $this->actingAs($titular->user)->get(route('atenciones.clinica.show', $propia))->assertOk()->assertDontSee($url)->assertDontSee('Texto de la otra operación');
            $this->patchJson($url, [$campo => 'Cambio', 'version' => $propia->versionClinica($campo)])->assertForbidden();
        }
        $this->assertDatabaseCount('auditorias', 4);
    }

    /** @return array<string, string> */
    private function huellasClinicas(): array
    {
        $huellas = [];
        foreach (['historias_clinicas', 'pacientes', 'citas', 'atenciones_medicas', 'remisiones_eps', 'cambios_estado_remision', 'auditorias'] as $tabla) {
            $huellas[$tabla] = hash('sha256', DB::table($tabla)->orderBy('id')->get()->toJson());
        }

        return $huellas;
    }

    /** @return array<string, array{string}> */
    public static function modalidadesCierre(): array
    {
        return [
            'particular' => ['PARTICULAR'], 'EPS vigente' => ['VIGENTE'],
            'EPS vencida por calendario' => ['CALENDARIO'], 'EPS vencida persistida' => ['VENCIDA'],
        ];
    }

    #[DataProvider('modalidadesCierre')]
    public function test_ca03101_y_ca03103_cierre_sincronizado_y_reintento_con_resumen_estable(string $caso): void
    {
        $medico = $this->medico(['RF-031']);
        $remision = RemisionEps::factory()->create(['vigencia_hasta' => '2030-05-02']);
        $atencion = $this->atencion($medico, $caso === 'PARTICULAR' ? null : $remision);
        $atencion->forceFill(['motivo_consulta' => 'Motivo clínico guardado', 'diagnostico_principal' => 'Diagnóstico guardado'])->save();
        $citaAntes = $atencion->cita->getAttributes();
        $historiaAntes = $atencion->historiaClinica->getAttributes();
        $remisionAntes = $remision->refresh()->getAttributes();
        if ($caso !== 'VIGENTE') {
            $this->travel(3)->days();
        }
        if ($caso === 'VENCIDA') {
            $remision->forceFill(['estado' => 'VENCIDA'])->save();
            $remision->eps->forceFill(['estado' => 'INACTIVA'])->save();
        }
        $guardar = route('atenciones.clinica.finalizar', $atencion);
        $pagina = route('atenciones.clinica.show', $atencion);
        $this->actingAs($medico->user)->get($pagina)->assertOk()->assertSee($guardar)->assertSee('Motivo clínico guardado')
            ->assertSee('Diagnóstico guardado')->assertDontSee('Guardar motivo')->assertDontSee('Guardar diagnóstico');
        $version = $atencion->versionClinica('cierre');
        $obs = '<b>Observación académica</b>';
        $this->post($guardar, ['confirmacion' => '1', 'observacion_final' => ' '.$obs.' ', 'version' => $version])
            ->assertRedirect($pagina)->assertSessionHasNoErrors();
        $this->assertSame('FINALIZADA', $atencion->refresh()->estado);
        $this->assertSame($obs, $atencion->observacion_final);
        $this->assertSame(now()->toDateTimeString(), $atencion->getRawOriginal('fecha_hora_fin'));
        $this->assertSame('ATENDIDA', $atencion->cita->estado);
        foreach (['id', 'paciente_id', 'medico_id', 'remision_id', 'motivo_consulta', 'remision_reservada'] as $campo) {
            $this->assertSame($citaAntes[$campo], $atencion->cita->getRawOriginal($campo));
        }
        $this->assertSame($historiaAntes, $atencion->historiaClinica->getAttributes());
        $this->assertSame($atencion->id, $atencion->historiaClinica->atenciones()->sole()->id);
        if ($caso === 'PARTICULAR') {
            $this->assertSame($remisionAntes, $remision->refresh()->getAttributes());
            $this->assertDatabaseCount('cambios_estado_remision', 0);
        } else {
            $this->assertSame('USADA', $remision->refresh()->estado);
            $cambio = $remision->cambiosEstado()->sole();
            $this->assertSame($caso === 'VIGENTE' ? 'VIGENTE' : 'VENCIDA', $cambio->estado_anterior);
            $this->assertSame('USADA', $cambio->estado_nuevo);
            $this->assertSame($medico->user_id, $cambio->user_id);
            $this->assertSame($remision->motivo_cambio_estado, $cambio->motivo);
            $this->assertSame(now()->toDateTimeString(), $cambio->getRawOriginal('fecha_cambio'));
            $this->assertFalse(RemisionEps::query()->whereKey($remision->id)->agendablePara($atencion->cita->fecha->toDateString())->exists());
        }
        $audit = Auditoria::query()->sole();
        $this->assertSame('ATENCION_FINALIZADA', $audit->accion);
        $this->assertSame($medico->user_id, $audit->user_id);
        $this->assertSame($atencion->id, $audit->registro_id);
        $this->assertSame(now()->toDateTimeString(), $audit->getRawOriginal('fecha_hora'));
        $this->assertStringNotContainsString($obs, $audit->descripcion);
        $this->get($pagina)->assertOk()->assertSee($obs)->assertDontSee($obs, false)->assertSee('FINALIZADA')->assertSee('ATENDIDA')
            ->assertSee('Motivo clínico guardado')->assertSee('Diagnóstico guardado')->assertDontSee($guardar);
        $this->get(route('atenciones.index'))->assertOk()->assertSee($pagina);
        $despues = $this->huellasClinicas();
        $this->travel(1)->day();
        $this->post($guardar, ['confirmacion' => '1', 'observacion_final' => 'Otro texto', 'version' => $version])
            ->assertRedirect($pagina)->assertSessionHas('status', 'La atención ya está finalizada.');
        $this->post($guardar, ['confirmacion' => '1'])
            ->assertRedirect($pagina)->assertSessionHas('status', 'La atención ya está finalizada.');
        $this->assertSame($despues, $this->huellasClinicas());
    }

    public function test_ca03102_identifica_todos_los_pendientes_sin_consumir_la_remision(): void
    {
        $atencion = $this->atencion(remision: RemisionEps::factory()->create());
        $this->actingAs($this->medico->user);
        foreach ([[null, null], ['Motivo guardado', '  '], ["\u{00A0}", 'Diagnóstico guardado']] as [$motivo, $diagnostico]) {
            $atencion->forceFill(['motivo_consulta' => $motivo, 'diagnostico_principal' => $diagnostico])->save();
            $antes = $this->huellasClinicas();
            $respuesta = $this->postJson(route('atenciones.clinica.finalizar', $atencion), ['confirmacion' => '1'])->assertUnprocessable();
            if ($motivo !== 'Motivo guardado') {
                $respuesta->assertJsonValidationErrors('motivo_consulta');
            }
            if ($diagnostico !== 'Diagnóstico guardado') {
                $respuesta->assertJsonValidationErrors('diagnostico_principal');
            }
            $this->assertSame($antes, $this->huellasClinicas());
        }
    }

    public function test_ca03104_exige_confirmacion_permiso_perfil_y_titularidad_y_rechaza_campos_protegidos(): void
    {
        $titular = $this->medico(['RF-031']);
        $atencion = $this->atencion($titular);
        $atencion->forceFill(['motivo_consulta' => 'Privado', 'diagnostico_principal' => 'Diagnóstico privado'])->save();
        $antes = $this->huellasClinicas();
        $ruta = route('atenciones.clinica.finalizar', $atencion);
        $pagina = route('atenciones.clinica.show', $atencion);
        $datos = ['confirmacion' => '1'];
        $this->postJson($ruta, $datos)->assertUnauthorized();
        $this->actingAs($this->medico->user)->get($pagina)->assertNotFound()->assertDontSee('Diagnóstico privado');
        $this->postJson($ruta, $datos)->assertNotFound()->assertDontSee('Diagnóstico privado');
        $administrador = $this->usuario(['RF-031']);
        $administrador->rol->update(['nombre' => 'Administrador']);
        $this->actingAs($administrador)->postJson($ruta, $datos)->assertForbidden();
        $this->actingAs($titular->user)->postJson($ruta, [])->assertUnprocessable()->assertJsonValidationErrors('confirmacion');
        $this->postJson($ruta, ['confirmacion' => '0'])->assertUnprocessable()->assertJsonValidationErrors('confirmacion');
        $extras = ['estado' => 'FINALIZADA', 'fecha_hora_fin' => '1999-01-01', 'paciente_id' => 1, 'medico_id' => 1, 'cita_id' => 1, 'historia_clinica_id' => 1, 'motivo_consulta' => 'Otro motivo', 'diagnostico_principal' => 'Otro diagnóstico'];
        $this->postJson($ruta, [...$datos, ...$extras])->assertUnprocessable()->assertJsonValidationErrors(array_keys($extras));
        $this->postJson($ruta, [...$datos, 'observacion_final' => []])->assertUnprocessable()->assertJsonValidationErrors('observacion_final');
        $titular->user->rol->permisos()->detach();
        $this->postJson($ruta, $datos)->assertForbidden();
        $titular->user->rol->permisos()->attach(Permiso::query()->where('codigo', 'RF-031')->sole());
        $titular->user->forceFill(['estado' => 'inactivo'])->save();
        $this->post($ruta, $datos)->assertRedirect(route('login'));
        $this->assertSame($antes, $this->huellasClinicas());
    }

    /** @return array<string, array{string}> */
    public static function fallosCierre(): array
    {
        return [
            'auditoría al final' => ['auditoría'], 'escritura de la remisión' => ['remisión'],
            'registro de trazabilidad' => ['traza'], 'actualización de cita' => ['cita'],
            'escritura de atención vetada' => ['veto atención'], 'registro de auditoría vetado' => ['veto auditoría'],
        ];
    }

    #[DataProvider('fallosCierre')]
    public function test_fallo_de_escritura_revierte_todo_el_cierre_eps(string $fallo): void
    {
        $atencion = $this->atencion(remision: RemisionEps::factory()->create());
        $atencion->forceFill(['motivo_consulta' => 'Motivo', 'diagnostico_principal' => 'Diagnóstico'])->save();
        $antes = $this->huellasClinicas();
        $error = static fn () => throw new RuntimeException('Fallo de escritura simulado.');
        match ($fallo) {
            'auditoría' => Auditoria::creating($error),
            'remisión' => RemisionEps::updating($error),
            'traza' => CambioEstadoRemision::creating($error),
            'cita' => Cita::updating($error),
            'veto atención' => AtencionMedica::updating(static fn () => false),
            'veto auditoría' => Auditoria::creating(static fn () => false),
        };
        $this->actingAs($this->medico->user)->postJson(route('atenciones.clinica.finalizar', $atencion), ['confirmacion' => '1', 'version' => $atencion->versionClinica('cierre')])
            ->assertInternalServerError();
        $this->assertSame($antes, $this->huellasClinicas());
    }

    public function test_revalida_vinculos_estados_y_vigencia_original_al_cerrar(): void
    {
        foreach (['USADA', 'ANULADA', 'aún no emitida', 'vencida al inicio', 'otro paciente', 'otra historia', 'cita incorrecta'] as $caso) {
            $remision = RemisionEps::factory()->create();
            $atencion = $this->atencion($this->medico(['RF-031']), $remision);
            $this->actingAs($atencion->medico->user);
            $atencion->forceFill(['motivo_consulta' => 'Motivo', 'diagnostico_principal' => 'Diagnóstico'])->save();
            match ($caso) {
                'USADA', 'ANULADA' => $remision->forceFill(['estado' => $caso])->save(),
                'aún no emitida' => $remision->update(['fecha_remision' => '2030-05-03']),
                'vencida al inicio' => $remision->update(['fecha_remision' => '2030-04-01', 'vigencia_hasta' => '2030-05-01']),
                'otro paciente' => $remision->update(['paciente_id' => Paciente::factory()->create()->id]),
                'otra historia' => $atencion->forceFill(['historia_clinica_id' => $this->atencion()->historia_clinica_id])->save(),
                'cita incorrecta' => $atencion->cita->forceFill(['estado' => 'PROGRAMADA'])->save(),
            };
            $antes = $this->huellasClinicas();
            $response = $this->postJson(route('atenciones.clinica.finalizar', $atencion), ['confirmacion' => '1', 'version' => $atencion->versionClinica('cierre')]);
            if ($caso === 'otra historia') {
                $response->assertNotFound();
                $this->get(route('atenciones.clinica.show', $atencion))->assertNotFound();
            } else {
                $response->assertUnprocessable()->assertJsonValidationErrors($caso === 'cita incorrecta' ? 'atencion' : 'remision');
            }
            $this->assertSame($antes, $this->huellasClinicas());
        }
    }

    public function test_cierre_desde_un_formulario_previo_requiere_revisar_el_cambio(): void
    {
        $atencion = $this->atencion();
        $atencion->forceFill(['motivo_consulta' => 'A', 'diagnostico_principal' => 'B'])->save();
        $version = $atencion->versionClinica('cierre');
        $this->actingAs($this->medico->user)->get(route('atenciones.clinica.show', $atencion))->assertOk()->assertSee($version);
        $this->patch(route('atenciones.clinica.diagnostico', $atencion), [
            'diagnostico_principal' => 'C', 'version' => $atencion->versionClinica('diagnostico_principal'),
        ])->assertSessionHasNoErrors();
        $antes = $this->huellasClinicas();
        $this->postJson(route('atenciones.clinica.finalizar', $atencion), ['confirmacion' => '1', 'version' => $version])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson(route('atenciones.clinica.finalizar', $atencion), ['confirmacion' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame($antes, $this->huellasClinicas());
    }

    public function test_migracion_preserva_identidad_indices_y_claves_y_rechaza_reversion_con_datos(): void
    {
        $migracion = require database_path('migrations/2026_11_23_041509_add_campos_clinicos_to_atenciones_medicas_table.php');
        $migracion->down();
        $tabla = 'atenciones_medicas';
        $atencion = $this->atencion();
        $antes = $atencion->getAttributes();
        $indices = Schema::getIndexes($tabla);
        $claves = Schema::getForeignKeys($tabla);
        $migracion->up();
        $despues = $atencion->refresh()->getAttributes();
        $this->assertSame($antes, array_intersect_key($despues, $antes));
        $this->assertSame(['motivo_consulta' => null, 'diagnostico_principal' => null, 'observacion_final' => null, 'fecha_hora_fin' => null], array_diff_key($despues, $antes));
        $this->assertSame($indices, Schema::getIndexes($tabla));
        $this->assertSame($claves, Schema::getForeignKeys($tabla));
        $atencion->forceFill(['motivo_consulta' => 'Texto conservado'])->save();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reversión eliminaría información clínica');
        $migracion->down();
    }

    public function test_seeder_idempotente_respeta_matriz_y_permisos_anteriores(): void
    {
        foreach (['Administrador', 'Médico', 'Administrativo'] as $nombre) {
            Rol::query()->firstOrCreate(['nombre' => $nombre]);
        }
        $previo = Permiso::query()->create(['codigo' => 'RF-028', 'descripcion' => 'Inicio']);
        $rol = Rol::query()->where('nombre', 'Médico')->sole();
        $rol->permisos()->attach($previo);
        $this->seed(CicloClinicoPermissionsSeeder::class);
        $asignaciones = DB::table('permiso_rol')->count();
        $this->seed(CicloClinicoPermissionsSeeder::class);
        $this->assertDatabaseCount('permiso_rol', $asignaciones);
        foreach (['RF-029', 'RF-030', 'RF-031'] as $codigo) {
            $roles = Permiso::query()->where('codigo', $codigo)->sole()->roles()->whereIn('nombre', ['Administrador', 'Médico', 'Administrativo'])->pluck('nombre')->all();
            $this->assertEqualsCanonicalizing(['Administrador', 'Médico'], $roles);
        }
        $this->assertTrue($rol->permisos()->whereKey($previo->id)->exists());
    }

    public function test_flujo_desde_la_reserva_rf028_hasta_el_resumen_final(): void
    {
        $medico = $this->medico(['RF-028', 'RF-029', 'RF-030', 'RF-031']);
        $paciente = Paciente::factory()->create();
        $historia = $paciente->historiaClinica()->create(['fecha_apertura' => now()]);
        $bloque = DisponibilidadMedica::factory()->for($medico)->create();
        $cita = Cita::factory()->for($paciente)->for($bloque, 'disponibilidad')->for($medico->user, 'creador')->create();
        $motivoAdministrativo = $cita->motivo_consulta;
        $paginaInicio = route('atenciones.show', $cita);
        $this->actingAs($medico->user)->get(route('atenciones.index'))->assertOk()->assertSee($paginaInicio);
        $this->post(route('atenciones.store', $cita))->assertRedirect($paginaInicio)->assertSessionHasNoErrors();
        $atencion = $cita->atencion()->sole();
        $this->assertSame([null, null, null, null], [$atencion->motivo_consulta, $atencion->diagnostico_principal, $atencion->observacion_final, $atencion->fecha_hora_fin]);
        $this->get($paginaInicio)->assertOk()->assertSee(route('atenciones.clinica.motivo', $atencion))->assertSee(route('atenciones.clinica.diagnostico', $atencion));
        foreach (self::campos() as [$ruta, $campo]) {
            $this->patch(route('atenciones.clinica.'.$ruta, $atencion), [
                $campo => 'Registro de '.$ruta, 'version' => $atencion->versionClinica($campo),
            ])->assertSessionHasNoErrors();
        }
        $pagina = route('atenciones.clinica.show', $atencion);
        $this->post(route('atenciones.clinica.finalizar', $atencion), ['confirmacion' => '1', 'version' => $atencion->refresh()->versionClinica('cierre')])
            ->assertRedirect($pagina)->assertSessionHasNoErrors();
        $this->get($paginaInicio)->assertRedirect($pagina);
        $this->get($pagina)->assertOk()->assertSee('FINALIZADA')->assertSee('Registro de motivo')->assertSee('Registro de diagnostico');
        $this->assertNull($atencion->refresh()->observacion_final);
        $this->assertSame($motivoAdministrativo, $cita->refresh()->motivo_consulta);
        $this->assertSame($atencion->id, $historia->atenciones()->sole()->id);
        $this->assertDatabaseCount('atenciones_medicas', 1);
        $this->assertDatabaseCount('historias_clinicas', 1);
        $this->assertSame(['ATENCION_INICIADA', 'MOTIVO_CONSULTA_REGISTRADO', 'DIAGNOSTICO_PRINCIPAL_REGISTRADO', 'ATENCION_FINALIZADA'], Auditoria::query()->orderBy('id')->pluck('accion')->all());
    }

    public function test_un_error_sql_clinico_no_expone_el_texto_ni_lo_guarda_parcialmente(): void
    {
        $atencion = $this->atencion();
        $antes = $this->huellasClinicas();
        $secreto = 'Contenido clínico ficticio reservado';
        AtencionMedica::updating(static fn () => throw new QueryException('sqlite', 'update atenciones_medicas set diagnostico_principal = ?', [$secreto], new PDOException('Fallo simulado')));
        Log::spy();
        $this->actingAs($this->medico->user)->patchJson(route('atenciones.clinica.diagnostico', $atencion), [
            'diagnostico_principal' => $secreto, 'version' => $atencion->versionClinica('diagnostico_principal'),
        ])->assertServiceUnavailable()->assertDontSee($secreto)->assertDontSee('update atenciones_medicas');
        Log::shouldHaveReceived('error')->once()->with('Error de base de datos en AtencionMedica.', [
            'modulo' => 'AtencionMedica', 'operacion' => 'atenciones.clinica.diagnostico',
        ]);
        $this->assertSame($antes, $this->huellasClinicas());
    }
}
