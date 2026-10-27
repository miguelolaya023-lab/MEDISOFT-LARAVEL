<?php

namespace Tests\Feature;

use App\Http\Requests\StoreCitaParticularRequest;
use App\Models\AntecedenteClinico;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\CitaPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CitaParticularTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing') || config('database.default') !== 'sqlite') {
            throw new LogicException('Se requiere SQLite en memoria.');
        }

        foreach ($this->connectionsToTransact() as $name) {
            $config = DB::connection($name)->getConfig();
            if ($config['driver'] !== 'sqlite' || $config['database'] !== ':memory:' || ! empty($config['url'])) {
                throw new LogicException('Conexión no aislada.');
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
    }

    private function actor(bool $conPermiso = true, array $cambios = []): User
    {
        $rol = Rol::query()->create(['nombre' => 'Agenda ficticia '.uniqid()]);
        if ($conPermiso) {
            $permiso = Permiso::query()->firstOrCreate(['codigo' => 'RF-024'], ['descripcion' => 'Agendar consulta particular']);
            $rol->permisos()->attach($permiso);
        }

        return User::factory()->create(['rol_id' => $rol->id, ...$cambios]);
    }

    private function medico(array $cuenta = [], array $perfil = []): User
    {
        $medico = $this->actor(false, $cuenta);
        $medico->medico()->create([
            'registro_profesional' => 'DEMO-'.uniqid(),
            'especialidad' => 'Medicina general',
            ...$perfil,
        ]);

        return $medico->refresh();
    }

    private function bloque(User $medico, array $cambios = []): DisponibilidadMedica
    {
        return DisponibilidadMedica::factory()->create([
            'medico_id' => $medico->medico->id,
            'fecha' => '2026-10-27',
            'hora_inicio' => '08:00:00',
            'hora_fin' => '10:00:00',
            'duracion_minutos' => 30,
            ...$cambios,
        ]);
    }

    private function datos(Paciente $paciente, User $medico, array $cambios = []): array
    {
        return [
            'paciente_id' => $paciente->id,
            'medico_id' => $medico->medico->id,
            'fecha' => '2026-10-27',
            'hora_inicio' => '08:00',
            'motivo_consulta' => 'Consulta ficticia de control',
            'valor_base' => '120000.50',
            'confirmacion' => '1',
            ...$cambios,
        ];
    }

    private function edicion(array $cambios = []): array
    {
        return [
            'fecha' => '2026-10-27',
            'hora_inicio' => '08:00',
            'hora_fin' => '10:00',
            'duracion_minutos' => 30,
            'confirmacion' => '1',
            ...$cambios,
        ];
    }

    private function cita(Paciente $paciente, User $medico, DisponibilidadMedica $bloque, array $cambios = []): Cita
    {
        return Cita::factory()->create([
            'paciente_id' => $paciente->id,
            'medico_id' => $medico->medico->id,
            'disponibilidad_medica_id' => $bloque->id,
            'fecha' => '2026-10-27',
            'hora_inicio' => '08:00:00',
            'hora_fin' => '08:30:00',
            'modalidad' => 'PARTICULAR',
            'estado' => 'PROGRAMADA',
            ...$cambios,
        ]);
    }

    public function test_ca02401_agenda_intervalo_y_registra_auditoria_sin_datos_clinicos(): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $bloque = $this->bloque($medico);
        $paciente = Paciente::factory()->create();

        $this->actingAs($actor)->get(route('citas.create', ['medico_id' => $medico->medico->id, 'fecha' => '2026-10-27']))
            ->assertOk()->assertSee('08:00')->assertSee('08:30');
        $respuesta = $this->post(route('citas.store'), $this->datos($paciente, $medico));
        $cita = Cita::query()->sole();
        $respuesta->assertRedirect(route('citas.confirmation', $cita));
        $this->assertSame($paciente->id, $cita->paciente_id);
        $this->assertSame($medico->medico->id, $cita->medico_id);
        $this->assertSame($bloque->id, $cita->disponibilidad_medica_id);
        $this->assertSame('2026-10-27', $cita->fecha->toDateString());
        $this->assertSame('08:00', substr($cita->hora_inicio, 0, 5));
        $this->assertSame('08:30', substr($cita->hora_fin, 0, 5));
        $this->assertSame('PARTICULAR', $cita->modalidad);
        $this->assertSame('PARTICULAR', $cita->tipo_paciente_al_agendar);
        $this->assertSame('PROGRAMADA', $cita->estado);
        $this->assertSame('120000.50', (string) $cita->valor_base);
        $this->assertSame($actor->id, $cita->created_by);
        $this->assertFalse(Schema::hasTable('atenciones_medicas'));
        $this->assertFalse(Schema::hasTable('pagos_particulares'));

        $auditoria = Auditoria::query()->sole();
        $this->assertSame('CITA_PARTICULAR_CREADA', $auditoria->accion);
        $this->assertSame('Cita', $auditoria->entidad);
        $this->assertSame($cita->id, $auditoria->registro_id);
        $this->assertSame($actor->id, $auditoria->user_id);
        $this->assertSame('EXITO', $auditoria->resultado);
        $this->assertStringNotContainsString($cita->motivo_consulta, $auditoria->toJson());
        $this->get(route('citas.confirmation', $cita))->assertOk()->assertSee((string) $cita->id);
    }

    public function test_ca02403_paciente_eps_conserva_preferencia_y_citas_anteriores(): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $bloque = $this->bloque($medico);
        $paciente = Paciente::factory()->create(['tipo_paciente' => 'EPS']);
        $historia = $paciente->historiaClinica()->create(['fecha_apertura' => now()->subMonth()]);
        $antecedente = AntecedenteClinico::factory()->create([
            'historia_clinica_id' => $historia->id,
            'medico_id' => $medico->medico->id,
            'tipo' => 'ALERGIA',
            'descripcion' => 'Registro ficticio anterior a la cita',
        ]);
        $anterior = $this->cita($paciente, $medico, $bloque, [
            'hora_inicio' => '08:30:00', 'hora_fin' => '09:00:00', 'tipo_paciente_al_agendar' => 'PARTICULAR',
        ]);
        $historiaAntes = $historia->refresh()->getAttributes();
        $antecedenteAntes = $antecedente->refresh()->getAttributes();
        $anteriorAntes = $anterior->refresh()->getAttributes();

        $this->actingAs($actor)->post(route('citas.store'), $this->datos($paciente, $medico))->assertSessionHasNoErrors();
        $nueva = Cita::query()->where('id', '!=', $anterior->id)->sole();
        $this->assertSame('PARTICULAR', $nueva->modalidad);
        $this->assertSame('EPS', $nueva->tipo_paciente_al_agendar);
        $nuevaAntes = $nueva->refresh()->getAttributes();
        $paciente->update(['tipo_paciente' => 'PARTICULAR']);
        $this->assertEquals($historiaAntes, $historia->refresh()->getAttributes());
        $this->assertEquals($antecedenteAntes, $antecedente->refresh()->getAttributes());
        $this->assertEquals($anteriorAntes, $anterior->refresh()->getAttributes());
        $this->assertEquals($nuevaAntes, $nueva->refresh()->getAttributes());
        $this->assertSame('PARTICULAR', $anterior->tipo_paciente_al_agendar);
        $this->assertSame('EPS', $nueva->tipo_paciente_al_agendar);
        $this->assertSame(1, $paciente->historiaClinica()->count());
        $this->assertSame(1, $historia->antecedentes()->count());
        $this->assertSame('PARTICULAR', $paciente->refresh()->tipo_paciente);
    }

    public static function solapamientos(): array
    {
        return [['08:00:00', '08:30:00'], ['07:45:00', '08:15:00'], ['08:15:00', '08:45:00'], ['07:30:00', '09:00:00']];
    }

    #[DataProvider('solapamientos')]
    public function test_ca02402_rechaza_duplicado_y_solapamiento_parcial_o_total(string $inicio, string $fin): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $bloque = $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $this->cita($paciente, $medico, $bloque, ['hora_inicio' => $inicio, 'hora_fin' => $fin]);

        $this->actingAs($actor)->postJson(route('citas.store'), $this->datos($paciente, $medico))
            ->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');
        $this->assertDatabaseCount('citas', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_cita_cancelada_libera_slot_y_citas_contiguas_no_chocan(): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $bloque = $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $this->cita($paciente, $medico, $bloque, ['estado' => 'CANCELADA']);
        $this->cita($paciente, $medico, $bloque, ['hora_inicio' => '08:30:00', 'hora_fin' => '09:00:00']);

        $this->actingAs($actor)->post(route('citas.store'), $this->datos($paciente, $medico))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('citas', 3);
        $this->assertSame('08:30', substr(Cita::query()->latest('id')->firstOrFail()->hora_fin, 0, 5));
    }

    public static function horasFueraDeSlot(): array
    {
        return [['07:30'], ['08:01'], ['09:45'], ['10:00']];
    }

    #[DataProvider('horasFueraDeSlot')]
    public function test_solo_permite_inicios_exactos_de_intervalos_completos(string $hora): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $this->bloque($medico, ['hora_fin' => '09:45:00', 'duracion_minutos' => 40]);
        $paciente = Paciente::factory()->create();

        $this->actingAs($actor)->postJson(route('citas.store'), $this->datos($paciente, $medico, ['hora_inicio' => $hora]))
            ->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');
        $this->assertDatabaseCount('citas', 0);
    }

    public function test_exige_instante_estrictamente_futuro_en_bogota(): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $this->bloque($medico, ['fecha' => '2026-10-26', 'hora_inicio' => '07:00:00', 'hora_fin' => '08:00:00']);
        $paciente = Paciente::factory()->create();

        $this->actingAs($actor)->postJson(route('citas.store'), $this->datos($paciente, $medico, [
            'fecha' => '2026-10-26', 'hora_inicio' => '07:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');
        $this->post(route('citas.store'), $this->datos($paciente, $medico, [
            'fecha' => '2026-10-26', 'hora_inicio' => '07:30',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('citas', 1);
    }

    public function test_acepta_limites_validos_de_importe_y_motivo(): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $this->bloque($medico);
        $paciente = Paciente::factory()->create();

        $this->actingAs($actor)->post(route('citas.store'), $this->datos($paciente, $medico, [
            'valor_base' => '9999999999.99', 'motivo_consulta' => str_repeat('x', 2000),
        ]))->assertSessionHasNoErrors();
        $cita = Cita::query()->sole();
        $this->assertSame('9999999999.99', (string) $cita->valor_base);
        $this->assertSame(2000, strlen($cita->motivo_consulta));
    }

    public static function entradasInvalidas(): array
    {
        return [
            ['paciente_id', null], ['paciente_id', []], ['paciente_id', 999999],
            ['medico_id', null], ['medico_id', []], ['medico_id', 999999],
            ['fecha', null], ['fecha', []], ['fecha', '2026-02-30'], ['fecha', '27/10/2026'],
            ['hora_inicio', null], ['hora_inicio', []], ['hora_inicio', '8:00'], ['hora_inicio', '24:00'],
            ['motivo_consulta', null], ['motivo_consulta', []], ['motivo_consulta', ''],
            ['motivo_consulta', str_repeat('x', 2001)],
            ['valor_base', null], ['valor_base', []], ['valor_base', true], ['valor_base', '0'],
            ['valor_base', '-1'], ['valor_base', '1.001'], ['valor_base', '1e3'],
            ['valor_base', '10000000000.00'], ['valor_base', '1,00'],
            ['confirmacion', null], ['confirmacion', []], ['confirmacion', '0'],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_ca02404_entradas_invalidas_no_reservan_ni_auditan(string $campo, mixed $valor): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $this->bloque($medico);
        $paciente = Paciente::factory()->create();

        $this->actingAs($actor)->postJson(route('citas.store'), $this->datos($paciente, $medico, [$campo => $valor]))
            ->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_rechaza_campos_ajenos_y_html_muestra_error_sin_escrituras(): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $this->bloque($medico);
        $paciente = Paciente::factory()->create();

        $this->actingAs($actor)->postJson(route('citas.store'), $this->datos($paciente, $medico, [
            'estado' => 'ATENDIDA', 'hora_fin' => '10:00', 'created_by' => $medico->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['estado', 'hora_fin', 'created_by']);
        $this->post(route('citas.store'), $this->datos($paciente, $medico, ['valor_base' => '-1']))
            ->assertInvalid('valor_base');
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function camposDerivadosManipulados(): array
    {
        return [
            'modalidad' => ['modalidad', 'EPS'],
            'duracion' => ['duracion', 60],
            'tipo_paciente_al_agendar' => ['tipo_paciente_al_agendar', 'EPS'],
            'user_id' => ['user_id', 999999],
            'remision_id' => ['remision_id', 999999],
        ];
    }

    #[DataProvider('camposDerivadosManipulados')]
    public function test_rechaza_individualmente_campos_derivados_sin_alterar_registros(string $campo, mixed $valor): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $bloque = $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $this->cita($paciente, $medico, $bloque, [
            'hora_inicio' => '09:00:00', 'hora_fin' => '09:30:00', 'created_by' => $actor->id,
        ]);
        $this->actingAs($actor);
        $datos = $this->datos($paciente, $medico);
        $snapshot = static function (): array {
            $tablas = [];
            foreach (Schema::getTableListing() as $tabla) {
                $filas = DB::table($tabla)->get()->map(static fn (object $fila): array => (array) $fila)->all();
                sort($filas);
                $tablas[$tabla] = $filas;
            }
            ksort($tablas);

            return $tablas;
        };
        $antes = $snapshot();

        $respuesta = $this->postJson(route('citas.store'), [...$datos, $campo => $valor])
            ->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->assertSame([$campo], array_keys($respuesta->json('errors')));
        $this->assertDatabaseCount('citas', 1);
        $this->assertDatabaseCount('auditorias', 0);
        $this->assertSame($antes, $snapshot());

        $this->postJson(route('citas.store'), $datos)->assertRedirect()->assertSessionHasNoErrors();
        $nueva = Cita::query()->latest('id')->firstOrFail();
        $this->assertDatabaseCount('citas', 2);
        $this->assertDatabaseCount('auditorias', 1);
        $this->assertSame('08:00:00', $nueva->hora_inicio);
        $this->assertSame('PARTICULAR', $nueva->modalidad);
        $this->assertSame('CITA_PARTICULAR_CREADA', Auditoria::query()->sole()->accion);
    }

    public function test_cargo_y_nombre_de_rol_administrador_no_conceden_rf024(): void
    {
        $actor = $this->actor(false, ['cargo' => 'Administrador']);
        $actor->rol->update(['nombre' => 'Administrador']);
        $medico = $this->medico();
        $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $this->actingAs($actor)->get(route('citas.create'))->assertForbidden();
        $this->postJson(route('citas.store'), $this->datos($paciente, $medico))->assertForbidden();
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_confirmacion_rf024_no_anticipa_acceso_a_modalidad_eps(): void
    {
        $actor = $this->actor();
        $cita = Cita::factory()->create(['created_by' => $actor->id, 'modalidad' => 'EPS', 'motivo_consulta' => 'MOTIVO_FICTICIO_OTRA_MODALIDAD']);
        $this->actingAs($actor)->get(route('citas.confirmation', $cita))->assertForbidden()->assertDontSee('MOTIVO_FICTICIO_OTRA_MODALIDAD');
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function cuentasInvalidas(): array
    {
        return [['sin_permiso'], ['inactivo'], ['externo']];
    }

    #[DataProvider('cuentasInvalidas')]
    public function test_ca02404_prohibe_acceso_directo_sin_autorizacion(string $caso): void
    {
        $actor = match ($caso) {
            'sin_permiso' => $this->actor(false),
            'inactivo' => $this->actor(true, ['estado' => 'inactivo']),
            'externo' => $this->actor(true, ['tipo_usuario' => 'externo']),
        };
        $medico = $this->medico();
        $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $estado = in_array($caso, ['inactivo', 'externo'], true) ? 302 : 403;

        $this->actingAs($actor)->get(route('citas.create'))->assertStatus($estado);
        $this->post(route('citas.store'), $this->datos($paciente, $medico))->assertStatus($estado);
        $this->assertDatabaseCount('citas', 0);
    }

    public function test_invitado_no_puede_ver_formulario_ni_reservar(): void
    {
        $medico = $this->medico();
        $this->bloque($medico);
        $paciente = Paciente::factory()->create();

        $this->get(route('citas.create'))->assertRedirect(route('login'));
        $this->post(route('citas.store'), $this->datos($paciente, $medico))->assertRedirect(route('login'));
        $this->assertDatabaseCount('citas', 0);
    }

    public static function destinosInvalidos(): array
    {
        return [['paciente_inactivo'], ['medico_inactivo'], ['perfil_incompleto']];
    }

    #[DataProvider('destinosInvalidos')]
    public function test_no_agenda_con_paciente_o_medico_inactivo_ni_perfil_incompleto(string $caso): void
    {
        $actor = $this->actor();
        $medico = $this->medico(
            $caso === 'medico_inactivo' ? ['estado' => 'inactivo'] : [],
            $caso === 'perfil_incompleto' ? ['registro_profesional' => ''] : [],
        );
        $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        if ($caso === 'paciente_inactivo') {
            $paciente->forceFill(['estado' => 'INACTIVO'])->save();
        }

        $campo = $caso === 'paciente_inactivo' ? 'paciente_id' : 'medico_id';
        $this->actingAs($actor)->postJson(route('citas.store'), $this->datos($paciente, $medico))
            ->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->assertDatabaseCount('citas', 0);
    }

    public function test_confirmacion_es_privada_para_creador_con_permiso_vigente(): void
    {
        $actor = $this->actor();
        $otro = $this->actor();
        $sinPermiso = $this->actor(false);
        $medico = $this->medico();
        $bloque = $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $cita = $this->cita($paciente, $medico, $bloque, ['created_by' => $actor->id]);

        $this->get(route('citas.confirmation', $cita))->assertRedirect(route('login'));
        $this->actingAs($otro)->get(route('citas.confirmation', $cita))->assertForbidden();
        $this->actingAs($sinPermiso)->get(route('citas.confirmation', $cita))->assertForbidden();
        $this->actingAs($actor)->get(route('citas.confirmation', $cita))->assertOk();
    }

    public static function revocaciones(): array
    {
        return [['permiso'], ['actor'], ['paciente'], ['medico'], ['perfil'], ['futuro']];
    }

    #[DataProvider('revocaciones')]
    public function test_revalida_dentro_de_transaccion_cambios_tras_form_request(string $caso): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $this->actingAs($actor);
        $this->app->afterResolving(StoreCitaParticularRequest::class, function () use ($caso, $actor, $medico, $paciente): void {
            match ($caso) {
                'permiso' => $actor->rol->permisos()->detach(),
                'actor' => $actor->update(['estado' => 'inactivo']),
                'paciente' => $paciente->forceFill(['estado' => 'INACTIVO'])->save(),
                'medico' => $medico->update(['estado' => 'inactivo']),
                'perfil' => $medico->medico()->update(['especialidad' => '']),
                'futuro' => $this->travelTo(Carbon::parse('2026-10-27 13:00:00', 'UTC')),
            };
        });

        $this->post(route('citas.store'), $this->datos($paciente, $medico))
            ->assertStatus(in_array($caso, ['permiso', 'actor'], true) ? 403 : 302);
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    /** SQLite omite FOR UPDATE; el comentario revela la intención SQL sin abrir otra conexión. */
    public function test_mutex_usuarios_paciente_medico_precede_consulta_de_solapamiento(): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $this->actingAs($actor);
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $nivelExterior = $db->transactionLevel();
        $consultas = [];
        $db->setQueryGrammar(new class($db) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, mixed $value): string
            {
                return $value === true ? '/* RF024_FOR_UPDATE_REQUESTED */' : '/* RF024_SHARED_LOCK_REQUESTED */';
            }
        });
        DB::listen(static function (QueryExecuted $query) use ($db, $nivelExterior, &$consultas): void {
            if ($db->transactionLevel() > $nivelExterior && str_starts_with(strtolower($query->sql), 'select')) {
                $consultas[] = $query->sql;
            }
        });

        try {
            $this->post(route('citas.store'), $this->datos($paciente, $medico))->assertSessionHasNoErrors();
        } finally {
            $db->setQueryGrammar($original);
        }

        $locks = array_values(array_filter($consultas, static fn (string $sql): bool => str_contains($sql, 'RF024_') && str_contains($sql, '_REQUESTED')));
        $this->assertCount(4, $locks);
        $this->assertSame($locks, array_slice($consultas, 0, 4));
        $this->assertStringContainsString('from "users"', $locks[0]);
        $this->assertStringContainsString('RF024_SHARED_LOCK_REQUESTED', $locks[0]);
        $this->assertStringContainsString('from "users"', $locks[1]);
        $this->assertStringContainsString('RF024_SHARED_LOCK_REQUESTED', $locks[1]);
        $this->assertStringContainsString('from "pacientes"', $locks[2]);
        $this->assertStringContainsString('RF024_SHARED_LOCK_REQUESTED', $locks[2]);
        $this->assertStringContainsString('from "medicos"', $locks[3]);
        $this->assertStringContainsString('RF024_FOR_UPDATE_REQUESTED', $locks[3]);
        $this->assertNotEmpty(array_filter($consultas, static fn (string $sql): bool => str_contains($sql, 'from "citas"')));
        foreach ($consultas as $sql) {
            if (str_contains($sql, 'from "citas"') || str_contains($sql, 'from "disponibilidades_medicas"')) {
                $this->assertStringNotContainsString('RF024_FOR_UPDATE_REQUESTED', $sql);
                $this->assertStringNotContainsString('RF024_SHARED_LOCK_REQUESTED', $sql);
            }
        }
        $this->assertSame($nivelExterior, $db->transactionLevel());
    }

    public function test_fallo_de_auditoria_revierte_reserva_y_conserva_bloque(): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $bloque = $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $antes = $bloque->getAttributes();
        Auditoria::creating(static function (): never {
            throw new RuntimeException('Fallo de auditoría ficticio');
        });

        try {
            $this->actingAs($actor);
            try {
                $this->withoutExceptionHandling()->post(route('citas.store'), $this->datos($paciente, $medico));
                $this->fail('El fallo de auditoría debe propagarse.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Fallo de auditoría ficticio', $exception->getMessage());
            }
        } finally {
            Auditoria::flushEventListeners();
        }

        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('auditorias', 0);
        $this->assertEquals($antes, $bloque->refresh()->getAttributes());
    }

    public function test_seeder_rf024_es_idempotente_y_conserva_permisos_ajenos(): void
    {
        $otro = Permiso::query()->create(['codigo' => 'RF-058', 'descripcion' => 'Permiso ficticio']);
        $administrador = Rol::query()->create(['nombre' => 'Administrador']);
        $administrador->permisos()->attach($otro);
        Rol::query()->create(['nombre' => 'Médico']);
        Rol::query()->create(['nombre' => 'Administrativo']);

        $this->seed(CitaPermissionsSeeder::class);
        $this->seed(CitaPermissionsSeeder::class);
        foreach (['Administrador', 'Médico', 'Administrativo'] as $rol) {
            $this->assertSame(1, Rol::query()->where('nombre', $rol)->firstOrFail()
                ->permisos()->where('codigo', 'RF-024')->count());
        }
        $this->assertSame(2, $administrador->permisos()->count());
    }

    public static function cambiosQueInvalidanCita(): array
    {
        return [
            'fecha distinta' => ['PROGRAMADA', '08:00:00', '08:30:00', ['fecha' => '2026-10-28'], 'put', true],
            'fin reducido' => ['PROGRAMADA', '09:30:00', '10:00:00', ['hora_fin' => '09:30'], 'patch', true],
            'inicio desplazado' => ['PROGRAMADA', '08:00:00', '08:30:00', ['hora_inicio' => '08:30'], 'put', false],
            'duracion 45' => ['PROGRAMADA', '08:00:00', '08:30:00', ['duracion_minutos' => 45], 'patch', false],
            'duracion 60' => ['PROGRAMADA', '08:00:00', '08:30:00', ['duracion_minutos' => 60], 'put', true],
            'en atención' => ['EN_ATENCION', '08:00:00', '08:30:00', ['fecha' => '2026-10-28'], 'patch', true],
            'atendida' => ['ATENDIDA', '08:00:00', '08:30:00', ['fecha' => '2026-10-28'], 'put', false],
        ];
    }

    #[DataProvider('cambiosQueInvalidanCita')]
    public function test_ca06003_rechaza_edicion_que_invalida_cualquier_cita_no_cancelada(
        string $estado,
        string $inicioCita,
        string $finCita,
        array $cambios,
        string $metodo,
        bool $json,
    ): void {
        $medico = $this->medico();
        $medico->rol->permisos()->attach(Permiso::query()->firstOrCreate(
            ['codigo' => 'RF-060'], ['descripcion' => 'Disponibilidad ficticia'],
        ));
        $bloque = $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $cita = $this->cita($paciente, $medico, $bloque, [
            'hora_inicio' => $inicioCita, 'hora_fin' => $finCita, 'estado' => $estado,
        ]);
        $bloqueAntes = $bloque->refresh()->getAttributes();
        $citaAntes = $cita->refresh()->getAttributes();
        $auditoriasAntes = Auditoria::query()->get()->map->getAttributes()->all();

        $respuesta = $this->actingAs($medico)->{$metodo.($json ? 'Json' : '')}(
            route('disponibilidad.update', $bloque), $this->edicion($cambios),
        );
        if ($json) {
            $respuesta->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');
        } else {
            $respuesta->assertInvalid('hora_inicio');
        }
        $this->assertEquals($bloqueAntes, $bloque->refresh()->getAttributes());
        $this->assertEquals($citaAntes, $cita->refresh()->getAttributes());
        $this->assertSame($auditoriasAntes, Auditoria::query()->get()->map->getAttributes()->all());
    }

    public static function cambiosQueConservanCita(): array
    {
        return [
            'extensión del final' => [['hora_fin' => '11:00'], 'put'],
            'inicio anclado en retícula compatible' => [['hora_inicio' => '07:30'], 'patch'],
        ];
    }

    #[DataProvider('cambiosQueConservanCita')]
    public function test_ca06003_permite_ampliar_bloque_si_conserva_intervalo_exacto(array $cambios, string $metodo): void
    {
        $medico = $this->medico();
        $medico->rol->permisos()->attach(Permiso::query()->firstOrCreate(
            ['codigo' => 'RF-060'], ['descripcion' => 'Disponibilidad ficticia'],
        ));
        $bloque = $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $cita = $this->cita($paciente, $medico, $bloque);
        $citaAntes = $cita->refresh()->getAttributes();
        $bloqueAntes = $bloque->refresh()->getAttributes();

        $this->actingAs($medico)->{$metodo}(route('disponibilidad.update', $bloque), $this->edicion($cambios))
            ->assertSessionHasNoErrors()->assertRedirect(route('disponibilidad.index'));
        $bloque->refresh();
        $this->assertSame($bloqueAntes['id'], $bloque->id);
        $this->assertSame($bloqueAntes['created_at'], $bloque->getRawOriginal('created_at'));
        $this->assertSame($citaAntes, $cita->refresh()->getAttributes());
        $this->assertContains(['inicio' => '08:00', 'fin' => '08:30'], $bloque->intervalos());
        $this->assertSame('DISPONIBILIDAD_ACTUALIZADA', Auditoria::query()->sole()->accion);
    }

    public function test_ca06003_ignora_cita_cancelada_al_editar_disponibilidad(): void
    {
        $medico = $this->medico();
        $medico->rol->permisos()->attach(Permiso::query()->firstOrCreate(
            ['codigo' => 'RF-060'], ['descripcion' => 'Disponibilidad ficticia'],
        ));
        $bloque = $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $cita = $this->cita($paciente, $medico, $bloque, ['estado' => 'CANCELADA']);

        $this->actingAs($medico)->put(route('disponibilidad.update', $bloque), [
            'fecha' => '2026-10-28', 'hora_inicio' => '08:00', 'hora_fin' => '10:00',
            'duracion_minutos' => 30, 'confirmacion' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-28', $bloque->refresh()->fecha->toDateString());
        $this->assertSame('CANCELADA', $cita->refresh()->estado);
    }

    public function test_integridad_rechaza_huerfanos_y_duplicado_vigente(): void
    {
        $actor = $this->actor();
        $medico = $this->medico();
        $bloque = $this->bloque($medico);
        $paciente = Paciente::factory()->create();
        $original = $this->cita($paciente, $medico, $bloque);
        $base = $original->getAttributes();
        unset($base['id'], $base['inicio_ocupado']);

        foreach ([
            ['paciente_id' => 999999, 'hora_inicio' => '08:30:00', 'hora_fin' => '09:00:00'],
            ['medico_id' => 999999, 'hora_inicio' => '08:30:00', 'hora_fin' => '09:00:00'],
            ['disponibilidad_medica_id' => 999999, 'hora_inicio' => '08:30:00', 'hora_fin' => '09:00:00'],
            ['created_by' => 999999, 'hora_inicio' => '08:30:00', 'hora_fin' => '09:00:00'],
            ['hora_inicio' => '08:00:00', 'hora_fin' => '08:30:00'],
        ] as $cambios) {
            try {
                DB::table('citas')->insert([...$base, 'created_by' => $actor->id, ...$cambios]);
                $this->fail('La integridad debe rechazar la cita.');
            } catch (QueryException) {
                $this->assertDatabaseCount('citas', 1);
            }
        }

        DB::table('citas')->insert([
            ...$base, 'created_by' => $actor->id, 'estado' => 'CANCELADA',
            'hora_inicio' => '08:00:00', 'hora_fin' => '08:30:00',
        ]);
        $this->assertDatabaseCount('citas', 2);
    }
}
