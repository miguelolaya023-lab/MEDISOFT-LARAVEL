<?php

namespace Tests\Feature;

use App\Models\AntecedenteClinico;
use App\Models\AtencionMedica;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\HistoriaClinica;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\HistoriaClinicaPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HistoriaClinicaTest extends TestCase
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

    /** @param list<string> $permisos */
    private function usuario(string $rol = 'Médico', array $permisos = ['RF-059'], array $atributos = []): User
    {
        $rol = Rol::query()->firstOrCreate(['nombre' => $rol]);
        foreach ($permisos as $codigo) {
            $rol->permisos()->syncWithoutDetaching([Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $codigo])->id]);
        }

        return User::factory()->for($rol)->create($atributos);
    }

    private function medico(User $actor, array $atributos = []): Medico
    {
        return $actor->medico()->create([
            'registro_profesional' => fake()->unique()->numerify('DEMO-########'),
            'especialidad' => 'Medicina general',
            ...$atributos,
        ]);
    }

    private function paciente(array $atributos = []): Paciente
    {
        $paciente = Paciente::factory()->create($atributos);
        $paciente->historiaClinica()->create(['fecha_apertura' => now()]);

        return $paciente;
    }

    private function atencion(
        Paciente $paciente,
        Medico $medico,
        string $inicio = '2026-11-23 15:00:00',
        string $estado = 'EN_CURSO',
        string $modalidad = 'PARTICULAR',
    ): AtencionMedica {
        $bloque = DisponibilidadMedica::factory()->for($medico)->create([
            'fecha' => now()->addDays($medico->disponibilidades()->count())->toDateString(),
        ]);
        $atributos = $modalidad === 'EPS'
            ? ['remision_id' => RemisionEps::factory()->for($paciente)->create()->id, 'valor_base' => null]
            : ['valor_base' => '918273.55'];
        $cita = Cita::factory()->for($paciente)->for($bloque, 'disponibilidad')->for($medico->user, 'creador')->create([
            'estado' => $estado === 'FINALIZADA' ? 'ATENDIDA' : 'EN_ATENCION',
            'modalidad' => $modalidad,
            ...$atributos,
        ]);

        return AtencionMedica::factory()->for($cita)->create([
            'fecha_hora_inicio' => $inicio,
            'fecha_hora_fin' => $estado === 'FINALIZADA' ? Carbon::parse($inicio, 'UTC')->addHour() : null,
            'estado' => $estado,
        ])->refresh();
    }

    private function agregarDocumentos(AtencionMedica $atencion, string $marca): void
    {
        $atencion->forceFill(['motivo_consulta' => 'Motivo '.$marca, 'diagnostico_principal' => 'Diagnóstico '.$marca])->save();
        $tratamiento = $atencion->tratamientos()->create([
            'descripcion' => 'Tratamiento '.$marca, 'fecha_registro' => now(),
            'clave_envio' => (string) Str::uuid(), 'huella_envio' => str_repeat('a', 64),
        ]);
        $tratamiento->increment('revision');
        $tratamiento->revisiones()->create([
            'user_id' => $atencion->medico->user_id, 'descripcion_anterior' => 'Revisión '.$marca,
            'fecha_correccion' => now(), 'revision' => 0,
        ]);
        $formula = $atencion->formulasMedicas()->create([
            'fecha_emision' => now(), 'observacion' => 'Observación '.$marca,
            'clave_envio' => (string) Str::uuid(), 'huella_envio' => str_repeat('b', 64),
        ]);
        $formula->detalles()->create(['posicion' => 1, 'medicamento' => 'Medicamento '.$marca, 'dosis' => 'Dosis '.$marca]);
    }

    private function agregarAntecedente(Paciente $paciente, Medico $medico, string $descripcion): AntecedenteClinico
    {
        return AntecedenteClinico::factory()->for($paciente->historiaClinica)->for($medico)->create(['descripcion' => $descripcion]);
    }

    private function ruta(Paciente $paciente, array $filtros = []): string
    {
        return route('pacientes.historia.show', ['paciente' => $paciente, ...$filtros]);
    }

    public function test_ca05901_muestra_atenciones_propias_con_sus_registros_y_antecedentes_de_distintos_autores(): void
    {
        $actor = $this->usuario(atributos: ['name' => 'Médico autor titular']);
        $medico = $this->medico($actor);
        $ajeno = $this->medico($this->usuario(atributos: ['name' => 'Médico autor de antecedente']));
        $paciente = $this->paciente();
        $primera = $this->atencion($paciente, $medico, '2026-11-22 15:00:00');
        $segunda = $this->atencion($paciente, $medico, '2026-11-23 15:00:00', 'FINALIZADA', 'EPS');
        $this->agregarDocumentos($primera, 'Propia-A <script>alert(1)</script>');
        $this->agregarDocumentos($segunda, 'Propia-B');
        $this->agregarAntecedente($paciente, $medico, 'Antecedente de mi autoría');
        $this->agregarAntecedente($paciente, $ajeno, 'Antecedente de otro autor');

        $this->actingAs($actor)->get($this->ruta($paciente))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('historia', fn (HistoriaClinica $historia) => $historia->is($paciente->historiaClinica))
            ->assertViewHas('atenciones', function ($atenciones) use ($primera, $segunda) {
                $this->assertSame([$segunda->id, $primera->id], $atenciones->pluck('id')->all());
                foreach ($atenciones as $atencion) {
                    $this->assertTrue($atencion->cita->is($atencion->is($primera) ? $primera->cita : $segunda->cita));
                    $this->assertSame($atencion->id, $atencion->tratamientos->sole()->atencion_medica_id);
                    $formula = $atencion->formulasMedicas->sole();
                    $this->assertSame($atencion->id, $formula->atencion_medica_id);
                    $this->assertSame($formula->id, $formula->detalles->sole()->formula_medica_id);
                }

                return true;
            })
            ->assertSee('En este alcance se muestran las atenciones del médico autenticado')
            ->assertSee('Antecedente de mi autoría')->assertSee('Antecedente de otro autor')
            ->assertSee('Médico autor titular')->assertSee('Médico autor de antecedente')
            ->assertSee('PARTICULAR')->assertSee('EPS')
            ->assertSee('Motivo Propia-A <script>alert(1)</script>')->assertSee('Diagnóstico Propia-B')
            ->assertSee('Tratamiento Propia-B')->assertSee('Revisión Propia-B')
            ->assertSee('Medicamento Propia-B')->assertSee('Dosis Propia-B')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('918273')->assertDontSee('Motivo administrativo ficticio');
    }

    public function test_ca05902_muestra_historia_vacia_existente_y_no_crea_historia_faltante(): void
    {
        $actor = $this->usuario();
        $this->medico($actor);
        $paciente = $this->paciente();
        $sinHistoria = Paciente::factory()->create();
        $historiaAntes = $paciente->historiaClinica->getRawOriginal();

        $this->actingAs($actor)->get($this->ruta($paciente))->assertOk()
            ->assertViewHas('atenciones', fn ($atenciones) => $atenciones->isEmpty())
            ->assertViewHas('antecedentes', fn ($antecedentes) => $antecedentes->isEmpty());
        $this->get($this->ruta($sinHistoria))->assertNotFound();

        $this->assertSame($historiaAntes, $paciente->historiaClinica->fresh()->getRawOriginal());
        foreach (['antecedentes_clinicos', 'atenciones_medicas', 'tratamientos', 'formulas_medicas', 'auditorias'] as $tabla) {
            $this->assertDatabaseCount($tabla, 0);
        }
        $this->assertDatabaseCount('historias_clinicas', 1);
    }

    public static function actores(): array
    {
        return [
            'médico autorizado' => ['Médico', ['RF-059'], 'habilitado', 'activo', 200],
            'administrador con perfil habilitado' => ['Administrador', ['RF-059'], 'habilitado', 'activo', 200],
            'CA-059-03 administrativo' => ['Administrativo', ['RF-010'], 'ausente', 'activo', 403],
            'administrativo con asignación clínica errónea' => ['Administrativo', ['RF-059'], 'habilitado', 'activo', 403],
            'CA-059-03 administrador sin perfil' => ['Administrador', ['RF-059'], 'ausente', 'activo', 403],
            'perfil no habilitado' => ['Administrador', ['RF-059'], 'incompleto', 'activo', 403],
            'RF-056, RF-057 y RF-058 no conceden RF-059' => ['Médico', ['RF-056', 'RF-057', 'RF-058'], 'habilitado', 'activo', 403],
            'CA-059-04 inactivo' => ['Médico', ['RF-059'], 'habilitado', 'inactivo', 302],
        ];
    }

    #[DataProvider('actores')]
    public function test_exige_permiso_clinico_independiente_perfil_y_actor_activo(string $rol, array $permisos, string $perfil, string $estado, int $codigo): void
    {
        $actor = $this->usuario($rol, $permisos, ['estado' => $estado]);
        if ($perfil !== 'ausente') {
            $this->medico($actor, $perfil === 'incompleto' ? ['especialidad' => ''] : []);
        }
        $paciente = $this->paciente();
        $this->agregarAntecedente($paciente, $this->medico($this->usuario('Otro médico', [])), 'Antecedente reservado QA');

        $respuesta = $this->actingAs($actor)->get($this->ruta($paciente))->assertStatus($codigo)
            ->assertHeader('Cache-Control', 'no-store, private');
        if ($codigo !== 200) {
            $respuesta->assertDontSee('Antecedente reservado QA');
        }
        if ($codigo === 302) {
            $respuesta->assertRedirect(route('login'));
        }
    }

    public function test_ca05904_rechaza_sesion_ausente_e_id_inexistente(): void
    {
        $paciente = $this->paciente();
        $actor = $this->usuario();
        $this->agregarAntecedente($paciente, $this->medico($actor), 'Antecedente confidencial QA');
        $this->get($this->ruta($paciente))->assertRedirect(route('login'))
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('Antecedente confidencial QA');
        $this->actingAs($actor)->get(route('pacientes.historia.show', ['paciente' => 99999]))->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('Antecedente confidencial QA');
    }

    public function test_solo_incluye_atenciones_propias_y_del_paciente_aunque_esten_finalizadas(): void
    {
        $actor = $this->usuario();
        $medico = $this->medico($actor);
        $ajeno = $this->medico($this->usuario());
        $paciente = $this->paciente(['nombres' => 'Nombre similar']);
        $otroPaciente = $this->paciente(['nombres' => 'Nombre similar']);
        $propia = $this->atencion($paciente, $medico);
        $otraPropia = $this->atencion($otroPaciente, $medico);
        $ajenaCurso = $this->atencion($paciente, $ajeno);
        $ajenaFinalizada = $this->atencion($paciente, $ajeno, estado: 'FINALIZADA');
        $this->agregarDocumentos($propia, 'Registro propio');
        $this->agregarDocumentos($otraPropia, 'Otra historia oculta');
        $this->agregarDocumentos($ajenaCurso, 'Curso ajeno oculto');
        $this->agregarDocumentos($ajenaFinalizada, 'Cierre ajeno oculto');
        $this->agregarAntecedente($otroPaciente, $medico, 'Antecedente de otra persona');

        $this->actingAs($actor)->get($this->ruta($paciente))->assertOk()
            ->assertViewHas('atenciones', fn ($atenciones) => $atenciones->pluck('id')->all() === [$propia->id])
            ->assertSee('Registro propio')->assertDontSee('Otra historia oculta')->assertDontSee('Curso ajeno oculto')
            ->assertDontSee('Cierre ajeno oculto')->assertDontSee('Antecedente de otra persona');
        $this->get(route('atenciones.clinica.show', $ajenaFinalizada))->assertForbidden()->assertDontSee('Cierre ajeno oculto');
        $this->get($this->ruta($paciente).'/atenciones/'.$ajenaCurso->id)->assertNotFound()->assertDontSee('Curso ajeno oculto');

        $otraPropia->forceFill(['historia_clinica_id' => $paciente->historiaClinica->id])->save();
        $this->get($this->ruta($paciente))->assertOk()
            ->assertViewHas('atenciones', fn ($atenciones) => $atenciones->pluck('id')->all() === [$propia->id])
            ->assertDontSee('Otra historia oculta');
    }

    public function test_paciente_inactivo_conserva_lectura_autorizada(): void
    {
        $actor = $this->usuario();
        $paciente = $this->paciente(['estado' => 'INACTIVO']);
        $atencion = $this->atencion($paciente, $this->medico($actor), estado: 'FINALIZADA');
        $this->agregarDocumentos($atencion, 'Registro histórico');
        $this->actingAs($actor)->get($this->ruta($paciente))->assertOk()->assertSee('Registro histórico');
        $this->assertSame('INACTIVO', $paciente->fresh()->estado);
    }

    public function test_rn04_rango_inclusivo_en_bogota_y_limites_individuales_solo_para_atenciones(): void
    {
        $actor = $this->usuario();
        $medico = $this->medico($actor);
        $paciente = $this->paciente();
        $antecedente = $this->agregarAntecedente($paciente, $medico, 'Antecedente independiente de fechas');
        $anterior = $this->atencion($paciente, $medico, '2026-11-23 04:59:59');
        $desde = $this->atencion($paciente, $medico, '2026-11-23 05:00:00');
        $hasta = $this->atencion($paciente, $medico, '2026-11-24 04:59:59');
        $posterior = $this->atencion($paciente, $medico, '2026-11-24 05:00:00');

        $this->actingAs($actor);
        $casos = [
            [['fechaInicial' => '2026-11-23', 'fechaFinal' => '2026-11-23'], [$hasta->id, $desde->id]],
            [['fechaInicial' => '2026-11-23'], [$posterior->id, $hasta->id, $desde->id]],
            [['fechaFinal' => '2026-11-23'], [$hasta->id, $desde->id, $anterior->id]],
            [['fechaInicial' => '2026-11-25'], []],
        ];
        foreach ($casos as [$filtros, $esperadas]) {
            $this->get($this->ruta($paciente, $filtros))->assertOk()
                ->assertViewHas('atenciones', fn ($atenciones) => $atenciones->pluck('id')->all() === $esperadas)
                ->assertViewHas('antecedentes', fn ($antecedentes) => $antecedentes->pluck('id')->all() === [$antecedente->id]);
        }
    }

    public static function fechasInvalidas(): array
    {
        return [
            'rango invertido' => [['fechaInicial' => '2026-11-24', 'fechaFinal' => '2026-11-23'], 'fechaInicial'],
            'fecha inexistente' => [['fechaInicial' => '2026-02-30'], 'fechaInicial'],
            'fecha con hora' => [['fechaFinal' => '2026-11-23T00:00:00Z'], 'fechaFinal'],
            'fecha como arreglo' => [['fechaInicial' => ['2026-11-23']], 'fechaInicial'],
        ];
    }

    #[DataProvider('fechasInvalidas')]
    public function test_ca05904_filtros_invalidos_no_exponen_datos_ni_escriben(array $filtros, string $campo): void
    {
        $actor = $this->usuario();
        $paciente = $this->paciente();
        $this->agregarAntecedente($paciente, $this->medico($actor), 'Contenido reservado al filtrar');
        $this->actingAs($actor)->getJson($this->ruta($paciente, $filtros))->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonValidationErrors($campo)->assertDontSee('Contenido reservado al filtrar');
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_paginas_independientes_conservan_filtro_y_desempatan_por_id(): void
    {
        $actor = $this->usuario();
        $medico = $this->medico($actor);
        $paciente = $this->paciente();
        $atenciones = [];
        $antecedentes = [];
        for ($i = 1; $i <= 11; $i++) {
            $atenciones[] = $this->atencion($paciente, $medico)->id;
            $antecedentes[] = $this->agregarAntecedente($paciente, $medico, 'Antecedente '.$i)->id;
        }
        $filtros = ['fechaInicial' => '2026-11-23', 'fechaFinal' => '2026-11-23'];

        $respuesta = $this->actingAs($actor)->get($this->ruta($paciente, $filtros))->assertOk()
            ->assertViewHas('atenciones', fn ($pagina) => $pagina->pluck('id')->all() === array_slice(array_reverse($atenciones), 0, 10))
            ->assertViewHas('antecedentes', fn ($pagina) => $pagina->count() === 10);
        $segundaPagina = $respuesta->viewData('atenciones')->url(2);
        parse_str(parse_url($segundaPagina, PHP_URL_QUERY), $queries);
        $this->assertSame($filtros['fechaInicial'], $queries['fechaInicial']);
        $this->assertSame($filtros['fechaFinal'], $queries['fechaFinal']);
        $this->assertSame('2', $queries['page']);
        $this->get($segundaPagina)->assertOk()
            ->assertViewHas('atenciones', fn ($pagina) => $pagina->pluck('id')->all() === [$atenciones[0]])
            ->assertViewHas('antecedentes', fn ($pagina) => $pagina->currentPage() === 1);
        $this->get($respuesta->viewData('antecedentes')->url(2))->assertOk()
            ->assertViewHas('antecedentes', fn ($pagina) => $pagina->currentPage() === 2 && $pagina->count() === 1)
            ->assertViewHas('atenciones', fn ($pagina) => $pagina->currentPage() === 1);
    }

    public function test_consulta_mantiene_numero_de_queries_sin_escribir(): void
    {
        $actor = $this->usuario();
        $medico = $this->medico($actor);
        $paciente = $this->paciente();
        $this->agregarDocumentos($this->atencion($paciente, $medico), 'Uno');
        $this->agregarAntecedente($paciente, $medico, 'Antecedente uno');
        $this->actingAs($actor);

        DB::enableQueryLog();
        try {
            $this->get($this->ruta($paciente))->assertOk();
            $primera = array_column(DB::getQueryLog(), 'query');

            for ($i = 2; $i <= 6; $i++) {
                $this->agregarDocumentos($this->atencion($paciente, $medico), 'Registro '.$i);
                $this->agregarAntecedente($paciente, $medico, 'Antecedente '.$i);
            }
            DB::flushQueryLog();
            $this->get($this->ruta($paciente))->assertOk();
            $segunda = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $consultaClinica = static fn (string $sql): bool => preg_match(
            '/\b(historias_clinicas|antecedentes_clinicos|atenciones_medicas|tratamientos|revisiones_tratamientos|formulas_medicas|detalles_formula)\b/i',
            $sql
        ) === 1;
        $this->assertCount(count(array_filter($primera, $consultaClinica)), array_filter($segunda, $consultaClinica),
            'El número de consultas clínicas debe ser constante al aumentar los registros.');
        $this->assertLessThanOrEqual(count($primera), count($segunda));
        foreach ([...$primera, ...$segunda] as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $sql);
        }
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_rf059_no_otorga_acceso_a_ficha_ni_a_escritura_clinica(): void
    {
        $actor = $this->usuario();
        $paciente = $this->paciente();
        $atencion = $this->atencion($paciente, $this->medico($actor));

        $this->actingAs($actor)->get($this->ruta($paciente))->assertOk()
            ->assertDontSee(route('atenciones.clinica.motivo', $atencion), false)
            ->assertDontSee(route('atenciones.clinica.diagnostico', $atencion), false)
            ->assertDontSee(route('atenciones.clinica.finalizar', $atencion), false)
            ->assertDontSee(route('atenciones.clinica.tratamientos.store', $atencion), false)
            ->assertDontSee(route('atenciones.clinica.formulas.store', $atencion), false)
            ->assertDontSee(route('antecedentes.store', $paciente), false);
        $this->get(route('pacientes.show', $paciente))->assertForbidden();
        $this->get(route('atenciones.clinica.show', $atencion))->assertForbidden();
        $this->post($this->ruta($paciente))->assertMethodNotAllowed();
        $this->patchJson(route('atenciones.clinica.motivo', $atencion), [])->assertForbidden();
        $this->patchJson(route('atenciones.clinica.diagnostico', $atencion), [])->assertForbidden();
        $this->postJson(route('atenciones.clinica.finalizar', $atencion), [])->assertForbidden();
        $this->postJson(route('atenciones.clinica.tratamientos.store', $atencion), [])->assertForbidden();
        $this->postJson(route('atenciones.clinica.formulas.store', $atencion), [])->assertForbidden();
        $this->postJson(route('antecedentes.store', $paciente), [])->assertForbidden();
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_ficha_administrativa_solo_muestra_enlace_a_historia_a_actor_autorizado(): void
    {
        $paciente = $this->paciente();
        $actor = $this->usuario(permisos: ['RF-010', 'RF-059']);
        $this->medico($actor);
        $administrativo = $this->usuario('Administrativo', ['RF-010']);
        $this->actingAs($actor)->get(route('pacientes.show', $paciente))->assertOk()->assertSee($this->ruta($paciente), false);
        $this->actingAs($administrativo)->get(route('pacientes.show', $paciente))->assertOk()->assertDontSee($this->ruta($paciente), false);
    }

    public static function filtrosHtmlInvalidos(): array
    {
        return [
            'fecha inválida' => [
                ['fechaFinal' => 'Valor sintético reservado'],
                'Ingrese una fecha final válida en formato AAAA-MM-DD.',
            ],
            'rango invertido' => [
                ['fechaInicial' => '2026-11-24', 'fechaFinal' => '2026-11-23'],
                'La fecha inicial debe ser anterior o igual a la fecha final.',
            ],
        ];
    }

    #[DataProvider('filtrosHtmlInvalidos')]
    public function test_filtro_html_invalido_no_refleja_el_valor_ni_guarda_datos_en_sesion(array $filtros, string $mensaje): void
    {
        $actor = $this->usuario();
        $paciente = $this->paciente();
        $medico = $this->medico($actor);
        $this->agregarAntecedente($paciente, $medico, 'Antecedente no disponible con filtro inválido');
        $this->agregarDocumentos($this->atencion($paciente, $medico), 'Contenido clínico reservado QA');
        $respuesta = $this->actingAs($actor)->get($this->ruta($paciente, $filtros))
            ->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewIs('pacientes.historia.filtros-invalidos')
            ->assertSee($mensaje)
            ->assertDontSee('Antecedente no disponible con filtro inválido')
            ->assertDontSee('Contenido clínico reservado QA')
            ->assertSessionMissing('_old_input');

        foreach ($filtros as $valor) {
            $respuesta->assertDontSee($valor);
        }
    }

    public function test_seeder_agrega_permiso_independiente_una_vez_y_preserva_los_existentes(): void
    {
        $this->usuario('Médico', ['RF-056', 'RF-057']);
        $this->usuario('Administrador', ['RF-058']);
        $this->usuario('Administrativo', ['RF-010']);
        $previas = DB::table('permiso_rol')->count();
        $this->seed(HistoriaClinicaPermissionsSeeder::class);
        $this->seed(HistoriaClinicaPermissionsSeeder::class);

        $this->assertSame($previas + 2, DB::table('permiso_rol')->count());
        $this->assertSame(['Administrador', 'Médico'], Rol::query()
            ->whereHas('permisos', fn ($permisos) => $permisos->where('codigo', 'RF-059'))->orderBy('nombre')->pluck('nombre')->all());
        $this->assertSame(1, Permiso::query()->where('codigo', 'RF-059')->count());
        $this->assertDatabaseMissing('permisos', ['codigo' => 'RF-013']);
    }
}
