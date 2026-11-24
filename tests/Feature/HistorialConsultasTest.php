<?php

namespace Tests\Feature;

use App\Models\AtencionMedica;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\HistorialConsultasPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HistorialConsultasTest extends TestCase
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

    private function usuario(array $permisos = ['RF-013'], string $rol = 'Médico', array $atributos = []): User
    {
        $rol = Rol::query()->firstOrCreate(['nombre' => $rol]);
        foreach ($permisos as $codigo) {
            $permiso = Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $codigo]);
            $rol->permisos()->syncWithoutDetaching([$permiso->id]);
        }

        return User::factory()->for($rol)->create($atributos);
    }

    private function medico(User $actor): Medico
    {
        return $actor->medico()->create([
            'registro_profesional' => fake()->unique()->numerify('QA-########'),
            'especialidad' => 'Medicina general',
        ]);
    }

    private function cita(Paciente $paciente, Medico $medico, string $estado, string $modalidad = 'PARTICULAR'): Cita
    {
        $bloque = DisponibilidadMedica::factory()->for($medico)->create([
            'fecha' => now()->addDays($medico->disponibilidades()->count())->toDateString(),
        ]);

        return Cita::factory()->for($paciente)->for($bloque, 'disponibilidad')->for($medico->user, 'creador')->create([
            'estado' => $estado,
            'modalidad' => $modalidad,
            'remision_id' => $modalidad === 'EPS' ? RemisionEps::factory()->for($paciente)->create()->id : null,
            'valor_base' => $modalidad === 'EPS' ? null : '120000.00',
        ]);
    }

    private function atencion(
        Paciente $paciente,
        Medico $medico,
        string $inicio = '2026-11-23 16:00:00',
        string $estado = 'EN_CURSO',
        string $modalidad = 'PARTICULAR',
    ): AtencionMedica {
        $cita = $this->cita($paciente, $medico, $estado === 'FINALIZADA' ? 'ATENDIDA' : 'EN_ATENCION', $modalidad);

        return AtencionMedica::factory()->for($cita)->create([
            'fecha_hora_inicio' => $inicio,
            'estado' => $estado,
            'motivo_consulta' => 'Motivo clínico reservado QA',
        ]);
    }

    private function resultados(Paciente $paciente, array $filtros = ['fechaInicial' => '2026-11-23', 'fechaFinal' => '2026-11-23']): string
    {
        return route('pacientes.historial.resultados', ['paciente' => $paciente, ...$filtros]);
    }

    public function test_ca01301_solo_las_atenciones_se_muestran_como_consultas(): void
    {
        $actor = $this->usuario(atributos: ['name' => 'Médico responsable QA']);
        $medico = $this->medico($actor);
        $paciente = Paciente::factory()->create();
        $this->cita($paciente, $medico, 'CANCELADA');
        $this->cita($paciente, $medico, 'PROGRAMADA');
        $atencion = $this->atencion($paciente, $medico);

        $this->actingAs($actor)->get($this->resultados($paciente))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('atenciones', fn ($atenciones) => $atenciones->pluck('id')->all() === [$atencion->id])
            ->assertSee('23/11/2026 11:00')
            ->assertSee('Médico responsable QA')
            ->assertSee('PARTICULAR')
            ->assertSee('EN_CURSO')
            ->assertDontSee('PROGRAMADA')
            ->assertDontSee('CANCELADA')
            ->assertDontSee('Motivo clínico reservado QA')
            ->assertDontSee('120000')
            ->assertDontSee('Ver atención');
    }

    public function test_ca01302_no_mezcla_pacientes_ni_atenciones_ajenas_en_ningun_estado(): void
    {
        $actor = $this->usuario();
        $medico = $this->medico($actor);
        $otroMedico = $this->medico(User::factory()->create());
        $paciente = Paciente::factory()->create(['estado' => 'INACTIVO']);
        $otroPaciente = Paciente::factory()->create();
        $primera = $this->atencion($paciente, $medico, '2026-11-23 15:00:00', 'EN_CURSO');
        $ultima = $this->atencion($paciente, $medico, '2026-11-23 16:00:00', 'FINALIZADA', 'EPS');
        $atencionOtroPaciente = $this->atencion($otroPaciente, $medico);
        $this->atencion($paciente, $otroMedico, estado: 'EN_CURSO');
        $this->atencion($paciente, $otroMedico, estado: 'FINALIZADA');

        $this->actingAs($actor)->get($this->resultados($paciente))->assertOk()
            ->assertViewHas('atenciones', fn ($atenciones) => $atenciones->pluck('id')->all() === [$ultima->id, $primera->id])
            ->assertSee('INACTIVO')->assertSee('EPS')->assertSee('FINALIZADA');
        $this->get($this->resultados($otroPaciente))->assertOk()
            ->assertViewHas('atenciones', fn ($atenciones) => $atenciones->pluck('id')->all() === [$atencionOtroPaciente->id]);
        $this->assertSame('INACTIVO', $paciente->refresh()->estado);
    }

    public function test_ca01303_muestra_el_estado_vacio_y_admite_fechas_futuras(): void
    {
        $actor = $this->usuario();
        $medico = $this->medico($actor);
        $paciente = Paciente::factory()->create();
        $this->atencion($paciente, $medico);

        $this->actingAs($actor)->get($this->resultados($paciente, ['fechaInicial' => '2090-01-01', 'fechaFinal' => '2090-01-31']))
            ->assertOk()->assertSee('No hay consultas en el rango seleccionado.');
    }

    public static function accesos(): array
    {
        return [
            'médico autorizado' => ['Médico', ['RF-013'], true, 'activo', 200],
            'administrador médico' => ['Administrador', ['RF-013'], true, 'activo', 200],
            'otros permisos clínicos' => ['Médico', ['RF-056', 'RF-057', 'RF-059'], true, 'activo', 403],
            'administrativo con asignación incorrecta' => ['Administrativo', ['RF-013'], true, 'activo', 403],
            'administrador sin perfil' => ['Administrador', ['RF-013'], false, 'activo', 403],
            'cuenta inactiva' => ['Médico', ['RF-013'], true, 'inactivo', 302],
        ];
    }

    #[DataProvider('accesos')]
    public function test_ca01304_autoriza_formulario_y_resultados_en_el_servidor(string $rol, array $permisos, bool $perfil, string $estado, int $codigo): void
    {
        $actor = $this->usuario($permisos, $rol, ['estado' => $estado]);
        if ($perfil) {
            $this->medico($actor);
        }
        $paciente = Paciente::factory()->create(['nombres' => 'Paciente ficticio protegido']);
        $this->actingAs($actor);

        foreach ([route('pacientes.historial.formulario', $paciente), $this->resultados($paciente)] as $ruta) {
            $respuesta = $this->get($ruta)->assertStatus($codigo)->assertHeader('Cache-Control', 'no-store, private');
            if ($codigo === 200) {
                $respuesta->assertSee('Paciente ficticio protegido');
            } else {
                $respuesta->assertDontSee('Paciente ficticio protegido');
            }
        }
    }

    public static function filtrosInvalidos(): array
    {
        return [
            'sin ambas fechas' => [[], 'Ingrese la fecha inicial.'],
            'sin fecha inicial' => [['fechaFinal' => '2026-11-23'], 'Ingrese la fecha inicial.'],
            'sin fecha final' => [['fechaInicial' => '2026-11-23'], 'Ingrese la fecha final.'],
            'rango invertido' => [['fechaInicial' => '2026-11-24', 'fechaFinal' => '2026-11-23'], 'La fecha final debe ser posterior o igual a la fecha inicial.'],
            'fecha inexistente' => [['fechaInicial' => '2026-02-30', 'fechaFinal' => '2026-11-23'], 'Ingrese una fecha inicial válida en formato AAAA-MM-DD.'],
            'fecha con hora' => [['fechaInicial' => '2026-11-23T01:00:00Z', 'fechaFinal' => '2026-11-23'], 'Ingrese una fecha inicial válida en formato AAAA-MM-DD.'],
            'parámetro ajeno' => [['fechaInicial' => '2026-11-23', 'fechaFinal' => '2026-11-23', 'observacion' => 'valor descartado'], 'La consulta contiene parámetros no admitidos.'],
        ];
    }

    #[DataProvider('filtrosInvalidos')]
    public function test_ca01304_los_filtros_invalidos_no_exponen_datos_ni_realizan_escrituras(array $filtros, string $mensaje): void
    {
        $actor = $this->usuario();
        $paciente = Paciente::factory()->create(['nombres' => 'Paciente ficticio protegido']);
        $this->atencion($paciente, $this->medico($actor));
        DB::enableQueryLog();

        $this->actingAs($actor)->get($this->resultados($paciente, $filtros))->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private')->assertSee($mensaje)
            ->assertDontSee('Paciente ficticio protegido')->assertDontSee('Motivo clínico reservado QA')
            ->assertDontSee('valor descartado')->assertSessionMissing('_old_input');
        $this->getJson($this->resultados($paciente, $filtros))->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('errors', fn (array $errores) => in_array($mensaje, array_merge(...array_values($errores)), true))
            ->assertDontSee('Motivo clínico reservado QA')->assertDontSee('valor descartado');

        $this->assertSoloLecturas();
    }

    public function test_bloquea_el_acceso_sin_sesion_y_paciente_inexistente(): void
    {
        $paciente = Paciente::factory()->create();
        $this->get($this->resultados($paciente))->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');

        $actor = $this->usuario();
        $this->medico($actor);
        $this->actingAs($actor)->get(route('pacientes.historial.formulario', ['paciente' => 999999]))
            ->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('pacientes.historial.resultados', ['paciente' => 999999, 'fechaInicial' => '2026-11-23', 'fechaFinal' => '2026-11-23']))
            ->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_filtra_por_inicio_en_el_rango_local_inclusivo_y_no_por_la_fecha_de_cita(): void
    {
        $actor = $this->usuario();
        $medico = $this->medico($actor);
        $paciente = Paciente::factory()->create();
        $anterior = $this->atencion($paciente, $medico, '2026-11-23 04:59:59');
        $apertura = $this->atencion($paciente, $medico, '2026-11-23 05:00:00');
        $cierre = $this->atencion($paciente, $medico, '2026-11-24 04:59:59');
        $posterior = $this->atencion($paciente, $medico, '2026-11-24 05:00:00');

        $this->actingAs($actor)->get($this->resultados($paciente))->assertOk()
            ->assertViewHas('atenciones', fn ($atenciones) => $atenciones->pluck('id')->all() === [$cierre->id, $apertura->id])
            ->assertSee('23/11/2026 00:00')->assertSee('23/11/2026 23:59');
        $this->get($this->resultados($paciente, ['fechaInicial' => '2026-11-22', 'fechaFinal' => '2026-11-24']))->assertOk()
            ->assertViewHas('atenciones', fn ($atenciones) => $atenciones->pluck('id')->all() === [$posterior->id, $cierre->id, $apertura->id, $anterior->id]);
    }

    public function test_pagina_con_orden_estable_y_filtros_sin_n_mas_uno_ni_escrituras(): void
    {
        $actor = $this->usuario();
        $medico = $this->medico($actor);
        $paciente = Paciente::factory()->create();
        $primera = $this->atencion($paciente, $medico);

        DB::enableQueryLog();
        $this->actingAs($actor)->get($this->resultados($paciente))->assertOk();
        $queriesConUna = $this->assertSoloLecturas();

        $ids = [$primera->id];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $this->atencion($paciente, $medico)->id;
        }

        DB::enableQueryLog();
        $this->get($this->resultados($paciente))->assertOk()
            ->assertViewHas('atenciones', function ($atenciones) use ($ids): bool {
                parse_str(parse_url($atenciones->nextPageUrl(), PHP_URL_QUERY), $query);

                return $atenciones->pluck('id')->all() === array_slice(array_reverse($ids), 0, 10)
                    && $query === ['fechaInicial' => '2026-11-23', 'fechaFinal' => '2026-11-23', 'page' => '2'];
            });
        $this->assertSame($queriesConUna, $this->assertSoloLecturas());

        $this->get($this->resultados($paciente, ['fechaInicial' => '2026-11-23', 'fechaFinal' => '2026-11-23', 'page' => 2]))->assertOk()
            ->assertViewHas('atenciones', fn ($atenciones) => $atenciones->pluck('id')->all() === [$primera->id]);
    }

    private function assertSoloLecturas(): int
    {
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower(ltrim($query['query'])));
        }

        return count(array_filter($queries, fn (array $query) => preg_match('/from "(atenciones_medicas|citas|medicos|users)"/i', $query['query']) === 1));
    }

    public function test_el_detalle_requiere_rf059_y_rechaza_ids_de_otro_medico_o_paciente(): void
    {
        $actor = $this->usuario();
        $medico = $this->medico($actor);
        $paciente = Paciente::factory()->create();
        $otroPaciente = Paciente::factory()->create();
        $propia = $this->atencion($paciente, $medico);
        $atencionAjena = $this->atencion($paciente, $this->medico(User::factory()->create()));
        $detalle = route('pacientes.historial.detalle', [$paciente, $propia]);

        $this->actingAs($actor)->get($detalle)->assertForbidden()->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('Motivo clínico reservado QA');
        $this->get(route('pacientes.historia.show', $paciente))->assertForbidden();

        $permiso = Permiso::query()->firstOrCreate(['codigo' => 'RF-059'], ['descripcion' => 'Historia clínica']);
        $actor->rol->permisos()->syncWithoutDetaching([$permiso->id]);
        $this->get($this->resultados($paciente))->assertOk()->assertSee($detalle)->assertDontSee('Motivo clínico reservado QA');
        $this->get($detalle)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Motivo clínico reservado QA');
        foreach ([[$otroPaciente, $propia], [$paciente, $atencionAjena]] as $parametros) {
            $this->get(route('pacientes.historial.detalle', $parametros))->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')
                ->assertDontSee('Motivo clínico reservado QA');
        }
    }

    public function test_ficha_muestra_el_acceso_solo_con_permiso_rf013(): void
    {
        $actor = $this->usuario(['RF-010', 'RF-013']);
        $this->medico($actor);
        $otroActor = $this->usuario(['RF-010'], 'Administrativo');
        $paciente = Paciente::factory()->create();

        $this->actingAs($actor)->get(route('pacientes.show', $paciente))->assertOk()->assertSee('Historial de consultas');
        $this->actingAs($otroActor)->get(route('pacientes.show', $paciente))->assertOk()->assertDontSee('Historial de consultas');
    }

    public function test_seeder_incremental_idempotente_para_medico_y_administrador(): void
    {
        $this->usuario(['RF-059']);
        $this->usuario(['RF-010'], 'Administrativo');
        $this->usuario(['RF-056'], 'Administrador');
        $previas = DB::table('permiso_rol')->count();

        $this->seed(HistorialConsultasPermissionsSeeder::class);
        $this->seed(HistorialConsultasPermissionsSeeder::class);

        $permiso = Permiso::query()->where('codigo', 'RF-013')->sole();
        $this->assertSame(['Administrador', 'Médico'], Rol::query()
            ->whereHas('permisos', fn ($q) => $q->whereKey($permiso->id))->orderBy('nombre')->pluck('nombre')->all());
        $this->assertDatabaseCount('permiso_rol', $previas + 2);
    }
}
