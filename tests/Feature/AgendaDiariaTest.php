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
use Database\Seeders\AgendaDiariaPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgendaDiariaTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA_HISTORICA = '2026-11-22';

    protected function beforeRefreshingDatabase(): void
    {
        $db = DB::connection();
        if (! $this->app->environment('testing') || $this->app->configurationIsCached()
            || $db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:' || $db->getConfig('url')) {
            throw new LogicException('La prueba requiere SQLite en memoria.');
        }
    }

    private function usuario(string $nombreRol, ?string $codigo = 'RF-032'): User
    {
        $rol = Rol::query()->firstOrCreate(['nombre' => $nombreRol]);
        if ($codigo !== null) {
            $permiso = Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => 'Permiso de prueba']);
            $rol->permisos()->syncWithoutDetaching([$permiso->id]);
        }

        return User::factory()->for($rol)->create();
    }

    private function medico(?User $usuario = null): Medico
    {
        $usuario ??= User::factory()->create();

        return $usuario->medico()->create([
            'registro_profesional' => 'DEMO-'.fake()->unique()->numerify('########'),
            'especialidad' => 'Medicina general',
        ]);
    }

    /** @param array<string, mixed> $atributos */
    private function cita(Medico $medico, array $atributos = [], string $fecha = self::FECHA_HISTORICA): Cita
    {
        $bloque = $medico->disponibilidades()->whereDate('fecha', $fecha)->first()
            ?? DisponibilidadMedica::factory()->for($medico)->create(['fecha' => $fecha]);

        return Cita::factory()->for($bloque, 'disponibilidad')->create($atributos)->refresh();
    }

    public function test_ca03201_lista_ambas_modalidades_y_orden_estable_solo_del_medico_y_dia_elegidos(): void
    {
        $medico = $this->medico($this->usuario('Médico'));
        $otroMedico = $this->medico();
        $pacienteEps = Paciente::factory()->create(['nombres' => 'Paciente de modalidad EPS', 'tipo_paciente' => 'PARTICULAR']);
        $remision = RemisionEps::factory()->for($pacienteEps)->create();
        $particular = $this->cita($medico, ['hora_inicio' => '09:00:00', 'hora_fin' => '09:30:00', 'estado' => 'EN_ATENCION']);
        $eps = $this->cita($medico, ['paciente_id' => $pacienteEps->id, 'modalidad' => 'EPS', 'remision_id' => $remision->id, 'valor_base' => null, 'hora_inicio' => '08:30:00', 'hora_fin' => '09:00:00']);
        $cancelada = $this->cita($medico, ['estado' => 'CANCELADA', 'hora_inicio' => '08:30:00', 'hora_fin' => '09:00:00']);
        $otra = $this->cita($otroMedico, ['paciente_id' => Paciente::factory()->create(['nombres' => 'Paciente de otro profesional'])->id]);
        $otroDia = $this->cita($medico, ['paciente_id' => Paciente::factory()->create(['nombres' => 'Paciente de otra fecha'])->id], '2026-11-23');

        $this->actingAs($medico->user)->get(route('agenda.diaria.index', ['fecha' => self::FECHA_HISTORICA]))
            ->assertOk()
            ->assertViewHas('citas', fn ($citas) => $citas->pluck('id')->all() === [$eps->id, $cancelada->id, $particular->id])
            ->assertSeeInOrder(['#'.$eps->id, 'Paciente de modalidad EPS', '>EPS<', 'PROGRAMADA', '#'.$cancelada->id, 'CANCELADA', '#'.$particular->id, '>PARTICULAR<', 'EN ATENCION'], false)
            ->assertDontSee($otra->paciente->nombres)->assertDontSee($otroDia->paciente->nombres)
            ->assertDontSee('name="medico_id"', false);
    }

    public function test_ca03202_consulta_un_dia_vacio_sin_paciente_y_conserva_medico_al_navegar(): void
    {
        $operador = $this->usuario('Administrativo');
        $medico = $this->medico();
        $this->travelTo(Carbon::parse('2026-11-23 02:00:00', 'UTC'));

        $this->actingAs($operador)->get(route('agenda.diaria.index'))
            ->assertOk()->assertSee('Seleccione un médico para consultar su agenda.');

        $this->get(route('agenda.diaria.index', ['medico_id' => $medico->id]))
            ->assertOk()->assertSee('22/11/2026')
            ->assertSee('No hay citas para el médico en esta fecha.')
            ->assertSee(route('agenda.diaria.index', ['fecha' => '2026-11-21', 'medico_id' => $medico->id]))
            ->assertSee(route('agenda.diaria.index', ['fecha' => '2026-11-23', 'medico_id' => $medico->id]))
            ->assertDontSee('name="paciente_id"', false);
        $this->assertDatabaseCount('pacientes', 0);
    }

    public function test_ca03203_muestra_cancelada_sin_ocupar_el_intervalo_ni_modificar_la_cita(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 13:00:00', 'UTC'));
        $medico = $this->medico($this->usuario('Médico'));
        $cita = $this->cita($medico, ['estado' => 'CANCELADA']);
        $antes = $cita->getAttributes();

        $this->actingAs($medico->user)->get(route('agenda.diaria.index', ['fecha' => self::FECHA_HISTORICA]))
            ->assertOk()->assertSee('CANCELADA')->assertSee($cita->horario());
        $this->assertContains('08:00', array_column($medico->intervalosLibres(self::FECHA_HISTORICA), 'inicio'));
        $this->assertSame($antes, $cita->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_ca03204_rechaza_filtros_invalidos_sin_exponer_citas(): void
    {
        $usuario = $this->usuario('Administrador');
        $medico = $this->medico();
        $cita = $this->cita($medico);
        $url = route('agenda.diaria.index');
        $antes = $cita->getAttributes();

        foreach (['2026-02-30', '22/11/2026', '0999-12-31', ''] as $fecha) {
            $this->actingAs($usuario)->getJson($url.'?'.http_build_query(['fecha' => $fecha, 'medico_id' => $medico->id]))
                ->assertUnprocessable()->assertJsonValidationErrors('fecha')->assertDontSee($cita->paciente->nombres);
        }
        $this->getJson($url.'?medico_id=9999')->assertUnprocessable()->assertJsonValidationErrors('medico_id')
            ->assertDontSee($cita->paciente->nombres);
        $this->assertSame($antes, $cita->refresh()->getAttributes());
    }

    public function test_ca03204_exige_permiso_propio_cuenta_activa_y_alcance_del_medico(): void
    {
        $titular = $this->medico($this->usuario('Médico'));
        $otro = $this->medico();
        $cita = $this->cita($otro);
        $url = route('agenda.diaria.index', ['medico_id' => $otro->id, 'fecha' => self::FECHA_HISTORICA]);

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($titular->user)->get($url)->assertForbidden()->assertDontSee($cita->paciente->nombres);
        $this->actingAs($this->usuario('Solo atención', 'RF-028'))->get($url)
            ->assertForbidden()->assertDontSee($cita->paciente->nombres);
        $this->actingAs($this->usuario('Administrador', null))->get($url)->assertForbidden();
        $inactivo = $this->usuario('Administrativo');
        $inactivo->update(['estado' => 'inactivo']);
        $this->actingAs($inactivo)->get($url)->assertRedirect(route('login'))->assertDontSee($cita->paciente->nombres);
    }

    public static function operadores(): array
    {
        return [['Administrador'], ['Administrativo']];
    }

    #[DataProvider('operadores')]
    public function test_operador_autorizado_ve_el_equivalente_a_cita_4_atendida_sin_datos_clinicos(string $rol): void
    {
        $usuarioMedico = User::factory()->create(['estado' => 'inactivo']);
        $medico = $this->medico($usuarioMedico);
        $paciente = Paciente::factory()->create(['estado' => 'INACTIVO']);
        $cita = $this->cita($medico, [
            'id' => 4, 'estado' => 'ATENDIDA', 'paciente_id' => $paciente->id,
            'motivo_consulta' => 'Motivo administrativo reservado',
        ]);
        AtencionMedica::factory()->for($cita)->create([
            'estado' => 'FINALIZADA', 'motivo_consulta' => 'Motivo clínico ficticio reservado',
            'diagnostico_principal' => 'Diagnóstico ficticio reservado', 'observacion_final' => 'Observación ficticia reservada',
            'fecha_hora_fin' => now(),
        ]);

        $respuesta = $this->actingAs($this->usuario($rol))->get(route('agenda.diaria.index', [
            'fecha' => self::FECHA_HISTORICA, 'medico_id' => $medico->id,
        ]));
        $respuesta->assertOk()->assertSeeInOrder(['#4', 'PARTICULAR', 'ATENDIDA'])
            ->assertSee($paciente->nombres)->assertSee($usuarioMedico->name)->assertSee('name="medico_id"', false)
            ->assertDontSee('Motivo administrativo reservado')->assertDontSee('Motivo clínico ficticio reservado')
            ->assertDontSee('Diagnóstico ficticio reservado')->assertDontSee('Observación ficticia reservada');
        $this->assertTrue($respuesta->headers->hasCacheControlDirective('no-store'));
    }

    public function test_carga_pacientes_en_una_sola_consulta_y_solo_lee(): void
    {
        $medico = $this->medico($this->usuario('Médico'));
        $this->cita($medico);
        $this->cita($medico, ['hora_inicio' => '08:30:00', 'hora_fin' => '09:00:00']);
        $this->cita($medico, ['hora_inicio' => '09:00:00', 'hora_fin' => '09:30:00']);

        DB::enableQueryLog();
        $this->actingAs($medico->user)->get(route('agenda.diaria.index', ['fecha' => self::FECHA_HISTORICA]))
            ->assertOk()->assertViewHas('citas', fn ($citas) => $citas->count() === 3);
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertCount(1, array_filter($consultas, fn ($sql) => str_contains($sql, 'from "pacientes"')));
        $this->assertCount(1, array_filter($consultas, fn ($sql) => str_contains($sql, 'from "citas"')));
        foreach ($consultas as $sql) {
            $this->assertStringStartsWith('select ', strtolower($sql));
            $this->assertStringNotContainsString('atenciones_medicas', $sql);
        }
    }

    public function test_seeder_incremental_es_idempotente_y_conserva_permisos_anteriores(): void
    {
        foreach (['Administrador', 'Administrativo', 'Médico'] as $rol) {
            $this->usuario($rol, 'RF-024');
        }
        $ajeno = $this->usuario('Otro rol', 'RF-028');
        $this->seed(AgendaDiariaPermissionsSeeder::class);
        $this->seed(AgendaDiariaPermissionsSeeder::class);
        $this->assertDatabaseCount('permiso_rol', 7);
        $this->assertFalse($ajeno->tienePermiso('RF-032'));
        $this->assertSame(['Administrador', 'Administrativo', 'Médico'], Rol::query()
            ->whereHas('permisos', fn ($permisos) => $permisos->where('codigo', 'RF-032'))
            ->orderBy('nombre')->pluck('nombre')->all());
    }
}
