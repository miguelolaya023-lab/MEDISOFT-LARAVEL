<?php

namespace Tests\Feature;

use App\Http\Requests\SaveAntecedenteClinicoRequest;
use App\Models\AntecedenteClinico;
use App\Models\Auditoria;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\RevisionAntecedenteClinico;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\AntecedentePermissionsSeeder;
use Database\Seeders\PacientePermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** RF-058: CA-058-01 a CA-058-04, sin citas ni atención médica. */
class AntecedenteClinicoTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing') || config('database.default') !== 'sqlite') {
            throw new LogicException('Pruebas canceladas: se requiere SQLite en memoria.');
        }
        foreach ($this->connectionsToTransact() as $name) {
            $config = $this->app['db']->connection($name)->getConfig();
            if ($config['driver'] !== 'sqlite' || $config['database'] !== ':memory:' || ! empty($config['url'])) {
                throw new LogicException('Pruebas canceladas: conexión no aislada.');
            }
        }
    }

    private function medico(bool $permiso = true, bool $perfil = true): User
    {
        $this->seed(AntecedentePermissionsSeeder::class);
        $rol = Rol::query()->create(['nombre' => 'Clínica '.uniqid()]);
        if ($permiso) {
            $rol->permisos()->attach(Permiso::query()->where('codigo', 'RF-058')->value('id'));
        }
        $user = User::factory()->create(['rol_id' => $rol->id]);
        if ($perfil) {
            $user->medico()->create(['registro_profesional' => 'DEMO-'.uniqid(), 'especialidad' => 'Medicina general']);
        }

        return $user->refresh();
    }

    private function paciente(string $estado = 'ACTIVO'): Paciente
    {
        $paciente = Paciente::factory()->create();
        $paciente->forceFill(['estado' => $estado])->save();
        $paciente->historiaClinica()->create(['fecha_apertura' => now()->subMonth()]);

        return $paciente->refresh();
    }

    private function antecedente(Paciente $paciente): AntecedenteClinico
    {
        return AntecedenteClinico::factory()->create([
            'historia_clinica_id' => $paciente->historiaClinica->id,
            'descripcion' => 'Contenido clínico ficticio inicial.', 'tipo' => 'Familiar',
            'fecha_registro' => now()->subDay(),
        ])->refresh();
    }

    private function url(string $accion, Paciente $paciente, ?AntecedenteClinico $antecedente = null): string
    {
        return route('antecedentes.'.$accion, ['paciente' => $paciente, ...($antecedente ? ['antecedente' => $antecedente] : [])]);
    }

    /** @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return ['descripcion' => 'Antecedente ficticio exclusivo del ámbito clínico.', 'tipo' => 'Personal', ...$cambios];
    }

    public function test_authorized_doctor_registers_without_appointment_or_patient_account_in_existing_history(): void
    {
        $user = $this->medico();
        $this->assertSame('interno', $user->tipo_usuario);
        $paciente = $this->paciente();
        $antes = $paciente->getAttributes();
        $historia = $paciente->historiaClinica->getAttributes();
        $cuentas = User::query()->count();
        $this->actingAs($user)->get($this->url('index', $paciente))->assertOk()->assertSee('Registrar antecedente');
        $this->post($this->url('store', $paciente), $this->datos(['descripcion' => '  Texto clínico ficticio  ']))
            ->assertSessionHasNoErrors()->assertRedirect($this->url('index', $paciente));
        $registro = AntecedenteClinico::query()->sole();
        $this->assertSame($paciente->historiaClinica->id, $registro->historia_clinica_id);
        $this->assertSame($user->medico->id, $registro->medico_id);
        $this->assertSame('Texto clínico ficticio', $registro->descripcion);
        $this->assertNotNull($registro->fecha_registro);
        $this->assertSame($antes, $paciente->refresh()->getAttributes());
        $this->assertSame($historia, $paciente->historiaClinica->getAttributes());
        $this->assertDatabaseCount('historias_clinicas', 1);
        $this->assertDatabaseCount('users', $cuentas);
        $this->assertFalse(Schema::hasColumn('pacientes', 'user_id'));
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('antecedente_clinico_revisiones', 0);
        $audit = Auditoria::query()->sole();
        $this->assertSame('ANTECEDENTE_REGISTRADO', $audit->accion);
        $this->assertSame('AntecedenteClinico', $audit->entidad);
        $this->assertSame($registro->id, $audit->registro_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('EXITO', $audit->resultado);
        $this->assertNotNull($audit->fecha_hora);
        $this->get($this->url('index', $paciente))->assertSee('Texto clínico ficticio');
        $this->get(route('pacientes.show', $paciente))->assertForbidden();
    }

    public function test_correction_keeps_same_record_history_original_date_and_author_and_preserves_previous_text(): void
    {
        $paciente = $this->paciente();
        $registro = $this->antecedente($paciente);
        $antes = $registro->getAttributes();
        $historia = $paciente->historiaClinica->getAttributes();
        $user = $this->medico();
        $this->actingAs($user)->get($this->url('edit', $paciente, $registro))->assertOk()->assertSee($registro->descripcion);
        $this->put($this->url('update', $paciente, $registro), $this->datos())->assertSessionHasNoErrors()->assertRedirect($this->url('index', $paciente));
        $registro->refresh();
        foreach (['id', 'historia_clinica_id', 'medico_id', 'fecha_registro', 'created_at'] as $campo) {
            $this->assertSame($antes[$campo], $registro->getRawOriginal($campo));
        }
        $this->assertSame($this->datos()['descripcion'], $registro->descripcion);
        $revision = $registro->revisiones()->sole();
        $this->assertSame($antes['descripcion'], $revision->descripcion);
        $this->assertSame($antes['tipo'], $revision->tipo);
        $this->assertSame($user->medico->id, $revision->medico_id);
        $this->assertNotNull($revision->fecha_correccion);
        $this->assertSame($historia, $paciente->historiaClinica->getAttributes());
        $this->assertDatabaseCount('antecedentes_clinicos', 1);
        $this->assertDatabaseCount('historias_clinicas', 1);
        $this->assertSame('ANTECEDENTE_CORREGIDO', Auditoria::query()->sole()->accion);
        $this->assertSame($user->id, Auditoria::query()->sole()->user_id);
    }

    public function test_successive_corrections_preserve_snapshots_and_unchanged_save_does_not_duplicate_them(): void
    {
        $paciente = $this->paciente();
        $registro = $this->antecedente($paciente);
        $this->actingAs($this->medico());
        foreach (['Segunda versión ficticia', 'Tercera versión ficticia'] as $descripcion) {
            $this->patch($this->url('update', $paciente, $registro), $this->datos(['descripcion' => $descripcion, 'tipo' => null]))->assertSessionHasNoErrors();
        }
        $this->assertSame(['Contenido clínico ficticio inicial.', 'Segunda versión ficticia'], $registro->revisiones()->orderBy('id')->pluck('descripcion')->all());
        $this->patch($this->url('update', $paciente, $registro), $this->datos(['descripcion' => 'Tercera versión ficticia', 'tipo' => null]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('antecedente_clinico_revisiones', 2);
        $this->assertDatabaseCount('auditorias', 2);
        $this->assertSame('Tercera versión ficticia', $registro->refresh()->descripcion);
    }

    public static function accesosNoAutorizados(): array
    {
        return [[false, true], [true, false], [false, false]];
    }

    #[DataProvider('accesosNoAutorizados')]
    public function test_permission_and_professional_profile_are_both_required_for_every_endpoint(bool $permiso, bool $perfil): void
    {
        $paciente = $this->paciente();
        $registro = $this->antecedente($paciente);
        $antes = $registro->getAttributes();
        $user = $this->medico($permiso, $perfil);
        $user->update(['cargo' => 'Administrador', 'tipo_usuario' => 'medico']);
        $this->actingAs($user)->get($this->url('index', $paciente))->assertForbidden()->assertDontSee($registro->descripcion);
        $this->get($this->url('edit', $paciente, $registro))->assertForbidden()->assertDontSee($registro->descripcion);
        $this->post($this->url('store', $paciente), $this->datos())->assertForbidden();
        $this->put($this->url('update', $paciente, $registro), $this->datos())->assertForbidden();
        $this->assertSame($antes, $registro->refresh()->getAttributes());
        $this->assertDatabaseCount('antecedentes_clinicos', 1);
        $this->assertDatabaseCount('antecedente_clinico_revisiones', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_v3_roles_do_not_bypass_profile_and_administrative_role_is_not_granted_permission(): void
    {
        $this->seed(AntecedentePermissionsSeeder::class);
        $paciente = $this->paciente();
        foreach (['Administrador', 'Médico'] as $nombre) {
            $user = User::factory()->create(['rol_id' => Rol::query()->where('nombre', $nombre)->value('id')]);
            $this->actingAs($user)->get($this->url('index', $paciente))->assertForbidden();
            $user->medico()->create(['registro_profesional' => 'DEMO-'.$user->id, 'especialidad' => 'Medicina general']);
            $this->get($this->url('index', $paciente))->assertOk();
            $this->post($this->url('store', $paciente), $this->datos())->assertSessionHasNoErrors();
        }
        $rol = Rol::query()->firstOrCreate(['nombre' => 'Administrativo']);
        $user = User::factory()->create(['rol_id' => $rol->id]);
        $user->medico()->create(['registro_profesional' => 'DEMO-A', 'especialidad' => 'Medicina general']);
        $this->actingAs($user)->get($this->url('index', $paciente))->assertForbidden();
        $this->assertFalse($rol->permisos()->where('codigo', 'RF-058')->exists());
    }

    public static function datosPerfil(): array
    {
        return [['registro_profesional'], ['especialidad']];
    }

    #[DataProvider('datosPerfil')]
    public function test_incomplete_professional_profile_is_rejected(string $campo): void
    {
        $user = $this->medico();
        $user->medico()->update([$campo => '   ']);
        $paciente = $this->paciente();
        $this->actingAs($user)->get($this->url('index', $paciente))->assertForbidden();
        $this->post($this->url('store', $paciente), $this->datos())->assertForbidden();
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_inactive_patient_allows_clinical_read_but_not_new_records_or_corrections(): void
    {
        $paciente = $this->paciente('INACTIVO');
        $registro = $this->antecedente($paciente);
        $antes = $registro->getAttributes();
        $this->actingAs($this->medico())->get($this->url('index', $paciente))->assertOk()->assertSee($registro->descripcion)
            ->assertSee('Paciente inactivo')->assertDontSee('name="descripcion"', false)->assertDontSee('Corregir antecedente');
        $this->get($this->url('edit', $paciente, $registro))->assertForbidden();
        $this->post($this->url('store', $paciente), $this->datos())->assertForbidden();
        $this->put($this->url('update', $paciente, $registro), $this->datos())->assertForbidden();
        $this->assertSame($antes, $registro->refresh()->getAttributes());
        $this->assertDatabaseCount('antecedente_clinico_revisiones', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function operaciones(): array
    {
        return [['store'], ['update']];
    }

    /** SQLite comprueba intención y orden; no demuestra bloqueos reales de InnoDB. */
    #[DataProvider('operaciones')]
    public function test_actor_shared_primary_key_lock_precedes_patient_and_clinical_writes(string $operacion): void
    {
        $paciente = $this->paciente();
        $registro = $this->antecedente($paciente);
        $actor = $this->medico();
        $this->assertNotSame($registro->medico_id, $actor->medico->id);
        $this->actingAs($actor);
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $nivelExterior = $db->transactionLevel();
        $consultas = [];
        $db->setQueryGrammar(new class($db) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, mixed $value): string
            {
                return $value === true ? '/* RF058_FOR_UPDATE_REQUESTED */' : '/* RF058_SHARED_LOCK_REQUESTED */';
            }
        });
        DB::listen(static function (QueryExecuted $query) use ($db, $nivelExterior, &$consultas): void {
            if ($db->transactionLevel() > $nivelExterior) {
                $consultas[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
            }
        });
        try {
            $this->call($operacion === 'store' ? 'POST' : 'PUT', $this->url($operacion, $paciente, $operacion === 'update' ? $registro : null), $this->datos())
                ->assertRedirect($this->url('index', $paciente))->assertSessionHasNoErrors();
        } finally {
            $db->setQueryGrammar($original);
        }

        $this->assertStringContainsString('from "users" where "users"."id" = ?', $consultas[0]['sql']);
        $this->assertStringContainsString('rf058_shared_lock_requested', $consultas[0]['sql']);
        $this->assertSame([$actor->id], $consultas[0]['bindings']);
        $this->assertStringContainsString('from "pacientes"', $consultas[1]['sql']);
        $this->assertStringContainsString('rf058_for_update_requested', $consultas[1]['sql']);
        $this->assertSame([$paciente->id], $consultas[1]['bindings']);
        $cuentas = array_values(array_filter($consultas, static fn (array $query): bool => str_contains($query['sql'], 'from "users"')));
        $this->assertCount(1, $cuentas);
        $this->assertSame($nivelExterior, $db->transactionLevel());
        $this->assertSame($actor->id, Auditoria::query()->sole()->user_id);
        if ($operacion === 'store') {
            $this->assertSame($actor->medico->id, AntecedenteClinico::query()->latest('id')->firstOrFail()->medico_id);
        } else {
            $this->assertSame($actor->medico->id, $registro->revisiones()->sole()->medico_id);
        }
    }

    public static function revocacionesClinicas(): array
    {
        $casos = [];
        foreach (['store', 'update'] as $operacion) {
            foreach (['cuenta', 'permiso', 'perfil', 'rol'] as $caso) {
                $casos[$operacion.'-'.$caso] = [$operacion, $caso];
            }
        }

        return $casos;
    }

    #[DataProvider('revocacionesClinicas')]
    public function test_fresh_locked_actor_is_reauthorized_after_request_validation(string $operacion, string $caso): void
    {
        $paciente = $this->paciente();
        $registro = $this->antecedente($paciente);
        $antes = $registro->getAttributes();
        $actor = $this->medico();
        $actor->load('medico');
        $sinPermisos = Rol::query()->create(['nombre' => 'Revocado ficticio']);
        $this->actingAs($actor);
        $cambiado = false;
        $this->app->afterResolving(SaveAntecedenteClinicoRequest::class, static function () use ($actor, $caso, $sinPermisos, &$cambiado): void {
            $cambiado = true;
            match ($caso) {
                'cuenta' => User::query()->whereKey($actor->id)->update(['estado' => 'inactivo']),
                'permiso' => $actor->rol->permisos()->detach(),
                'perfil' => $actor->medico()->update(['especialidad' => '']),
                'rol' => User::query()->whereKey($actor->id)->update(['rol_id' => $sinPermisos->id]),
            };
        });

        $this->call($operacion === 'store' ? 'POST' : 'PUT', $this->url($operacion, $paciente, $operacion === 'update' ? $registro : null), $this->datos())->assertForbidden();
        $this->assertTrue($cambiado);
        $this->assertSame($antes, $registro->refresh()->getAttributes());
        $this->assertDatabaseCount('antecedentes_clinicos', 1);
        $this->assertDatabaseCount('antecedente_clinico_revisiones', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('operaciones')]
    public function test_patient_state_is_rechecked_inside_write_transaction(string $operacion): void
    {
        $paciente = $this->paciente();
        $registro = $this->antecedente($paciente);
        $antes = $registro->getAttributes();
        $this->actingAs($this->medico());
        $cambiado = false;
        Gate::after(function (User $user, string $ability) use ($paciente, &$cambiado): void {
            if (! $cambiado && $ability === 'create') {
                $cambiado = true;
                Paciente::query()->whereKey($paciente->id)->update(['estado' => 'INACTIVO']);
            }
        });
        $this->call($operacion === 'store' ? 'POST' : 'PUT', $this->url($operacion, $paciente, $operacion === 'update' ? $registro : null), $this->datos())->assertInvalid('descripcion');
        $this->assertTrue($cambiado);
        $this->assertSame($antes, $registro->refresh()->getAttributes());
        $this->assertDatabaseCount('antecedentes_clinicos', 1);
        $this->assertDatabaseCount('antecedente_clinico_revisiones', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_guests_and_inactive_accounts_cannot_access_clinical_endpoints(): void
    {
        $paciente = $this->paciente();
        $registro = $this->antecedente($paciente);
        $user = $this->medico();
        $user->update(['estado' => 'inactivo']);
        foreach ([null, $user] as $actor) {
            foreach ([['GET', 'index'], ['GET', 'edit'], ['POST', 'store'], ['PUT', 'update']] as [$metodo, $accion]) {
                if ($actor) {
                    $this->actingAs($actor);
                }
                $this->call($metodo, $this->url($accion, $paciente, in_array($accion, ['edit', 'update']) ? $registro : null), $this->datos())->assertRedirect('/login');
                $this->assertGuest();
            }
        }
        $this->assertDatabaseCount('auditorias', 0);
        $this->assertDatabaseCount('antecedentes_clinicos', 1);
    }

    public function test_missing_patient_or_history_is_not_created_implicitly(): void
    {
        $this->actingAs($this->medico());
        $this->get('/pacientes/999999/antecedentes')->assertNotFound();
        $this->post('/pacientes/999999/antecedentes', $this->datos())->assertNotFound();
        $this->put('/pacientes/999999/antecedentes/1', $this->datos())->assertNotFound();
        $paciente = Paciente::factory()->create();
        $this->get($this->url('index', $paciente))->assertNotFound();
        $this->post($this->url('store', $paciente), $this->datos())->assertNotFound();
        $this->assertDatabaseCount('historias_clinicas', 0);
        $this->assertDatabaseCount('antecedentes_clinicos', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_cross_patient_or_nonexistent_antecedent_cannot_be_edited(): void
    {
        $paciente = $this->paciente();
        $otro = $this->paciente();
        $registro = $this->antecedente($otro);
        $antes = $registro->getAttributes();
        $this->actingAs($this->medico());
        foreach ([$registro->id, 999999, 'invalido'] as $id) {
            $this->get('/pacientes/'.$paciente->id.'/antecedentes/'.$id.'/edit')->assertNotFound();
            $this->put('/pacientes/'.$paciente->id.'/antecedentes/'.$id, $this->datos())->assertNotFound();
        }
        $this->assertSame($antes, $registro->refresh()->getAttributes());
        $this->assertDatabaseCount('antecedente_clinico_revisiones', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function valoresInvalidos(): array
    {
        return [['descripcion', null], ['descripcion', ''], ['descripcion', '  '], ['descripcion', ['texto']], ['descripcion', 123], ['descripcion', str_repeat('á', 10001)], ['tipo', ['texto']], ['tipo', str_repeat('a', 101)]];
    }

    #[DataProvider('valoresInvalidos')]
    public function test_validation_rejects_invalid_input_in_registration_and_correction(string $campo, mixed $valor): void
    {
        $paciente = $this->paciente();
        $registro = $this->antecedente($paciente);
        $antes = $registro->getAttributes();
        $this->actingAs($this->medico());
        $this->post($this->url('store', $paciente), $this->datos([$campo => $valor]))->assertInvalid($campo);
        $this->put($this->url('update', $paciente, $registro), $this->datos([$campo => $valor]))->assertInvalid($campo);
        $this->assertSame($antes, $registro->refresh()->getAttributes());
        $this->assertDatabaseCount('antecedentes_clinicos', 1);
        $this->assertDatabaseCount('antecedente_clinico_revisiones', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function identificadoresManipulados(): array
    {
        return [['paciente_id', 999], ['historia_clinica_id', 999], ['antecedente_clinico_id', 999], ['id', 999], ['medico_id', 999], ['user_id', 999], ['fecha_registro', '2000-01-01'], ['fecha_correccion', '2000-01-01'], ['created_at', '2000-01-01'], ['updated_at', '2000-01-01'], ['estado', 'ACTIVO'], ['historia_clinica_id', null], ['revisiones', []]];
    }

    #[DataProvider('identificadoresManipulados')]
    public function test_identifiers_authors_dates_and_revisions_cannot_be_overridden(string $campo, mixed $valor): void
    {
        $paciente = $this->paciente();
        $registro = $this->antecedente($paciente);
        $antes = $registro->getAttributes();
        $this->actingAs($this->medico());
        $this->post($this->url('store', $paciente), $this->datos([$campo => $valor]))->assertInvalid($campo);
        $this->put($this->url('update', $paciente, $registro), $this->datos([$campo => $valor]))->assertInvalid($campo);
        $this->assertSame($antes, $registro->refresh()->getAttributes());
        $this->assertDatabaseCount('antecedentes_clinicos', 1);
        $this->assertDatabaseCount('antecedente_clinico_revisiones', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('operaciones')]
    public function test_audit_failure_rolls_back_clinical_record_and_revision(string $operacion): void
    {
        $paciente = $this->paciente();
        $historia = $paciente->historiaClinica->getAttributes();
        $registro = $this->antecedente($paciente);
        $antes = $registro->getAttributes();
        $this->actingAs($this->medico())->withoutExceptionHandling();
        Auditoria::creating(function (): void {
            throw new RuntimeException('Fallo simulado de auditoría');
        });
        try {
            $this->call($operacion === 'store' ? 'POST' : 'PUT', $this->url($operacion, $paciente, $operacion === 'update' ? $registro : null), $this->datos());
            $this->fail('Se esperaba fallo de auditoría.');
        } catch (RuntimeException $e) {
            $this->assertSame('Fallo simulado de auditoría', $e->getMessage());
            $this->assertSame($antes, $registro->refresh()->getAttributes());
            $this->assertSame($historia, $paciente->historiaClinica->getAttributes());
            $this->assertDatabaseCount('antecedentes_clinicos', 1);
            $this->assertDatabaseCount('antecedente_clinico_revisiones', 0);
            $this->assertDatabaseCount('auditorias', 0);
        } finally {
            Auditoria::flushEventListeners();
        }
    }

    public function test_clinical_content_is_escaped_and_not_copied_to_audit_or_administrative_pages(): void
    {
        $paciente = $this->paciente();
        $texto = '<script>Contenido clínico ficticio confidencial</script>';
        $this->actingAs($this->medico())->post($this->url('store', $paciente), $this->datos(['descripcion' => $texto, 'tipo' => 'Tipo clínico privado']))->assertSessionHasNoErrors();
        $registro = AntecedenteClinico::query()->sole();
        $this->get($this->url('index', $paciente))->assertSee(e($texto), false)->assertDontSee($texto, false);
        $this->put($this->url('update', $paciente, $registro), $this->datos())->assertSessionHasNoErrors();
        foreach (Auditoria::query()->get() as $audit) {
            foreach ([$texto, 'Tipo clínico privado', $this->datos()['descripcion'], $paciente->numero_documento] as $sensible) {
                $this->assertStringNotContainsString($sensible, $audit->descripcion);
            }
        }
        $this->assertSame($texto, $registro->revisiones()->sole()->descripcion);
        $sensibles = [$registro->refresh()->descripcion, ...$registro->revisiones()->pluck('descripcion')->all()];
        $this->seed(PacientePermissionsSeeder::class);
        $admin = User::factory()->create(['rol_id' => Rol::query()->where('nombre', 'Administrador')->value('id')]);
        $this->actingAs($admin);
        foreach ([route('pacientes.show', $paciente), route('pacientes.index'), route('pacientes.search', ['buscar' => $paciente->numero_documento])] as $url) {
            $respuesta = $this->get($url)->assertOk()->assertDontSee('Antecedentes clínicos');
            foreach ($sensibles as $contenido) {
                $respuesta->assertDontSee($contenido, false)->assertDontSee(e($contenido), false);
            }
        }
        foreach ([
            $this->get($this->url('index', $paciente)),
            $this->get($this->url('edit', $paciente, $registro)),
            $this->post($this->url('store', $paciente), $this->datos()),
            $this->put($this->url('update', $paciente, $registro), $this->datos()),
        ] as $respuesta) {
            $respuesta->assertForbidden();
            foreach ($sensibles as $contenido) {
                $respuesta->assertDontSee($contenido, false)->assertDontSee(e($contenido), false);
            }
        }
    }

    public function test_optional_type_and_technical_length_limits_accept_valid_values(): void
    {
        $paciente = $this->paciente();
        $this->actingAs($this->medico())->post($this->url('store', $paciente), ['descripcion' => str_repeat('á', 10000)])
            ->assertSessionHasNoErrors();
        $registro = AntecedenteClinico::query()->sole();
        $this->assertNull($registro->tipo);
        $this->put($this->url('update', $paciente, $registro), $this->datos(['tipo' => str_repeat('a', 100)]))->assertSessionHasNoErrors();
        $this->assertSame(100, strlen($registro->refresh()->tipo));
    }

    public function test_permission_seeder_is_idempotent_preserves_assignments_and_does_not_add_rf059(): void
    {
        $this->seed(PacientePermissionsSeeder::class);
        $admin = Rol::query()->where('nombre', 'Administrador')->firstOrFail();
        $antes = $admin->permisos()->pluck('codigo')->all();
        $this->seed(AntecedentePermissionsSeeder::class);
        $this->seed(AntecedentePermissionsSeeder::class);
        foreach ($antes as $codigo) {
            $this->assertTrue($admin->permisos()->where('codigo', $codigo)->exists());
        }
        foreach (['Administrador', 'Médico'] as $nombre) {
            $this->assertTrue(Rol::query()->where('nombre', $nombre)->first()->permisos()->where('codigo', 'RF-058')->exists());
        }
        $this->assertFalse(Rol::query()->where('nombre', 'Administrativo')->first()->permisos()->where('codigo', 'RF-058')->exists());
        $this->assertSame(1, Permiso::query()->where('codigo', 'RF-058')->count());
        $this->assertSame(0, Permiso::query()->where('codigo', 'RF-059')->count());
    }

    public function test_there_are_no_clinical_delete_or_full_history_routes_and_revision_factory_is_usable(): void
    {
        $paciente = $this->paciente();
        $registro = $this->antecedente($paciente);
        $revision = RevisionAntecedenteClinico::factory()->create(['antecedente_clinico_id' => $registro->id]);
        $this->assertSame($registro->id, $revision->antecedenteClinico->id);
        $this->actingAs($this->medico())->delete($this->url('update', $paciente, $registro))->assertMethodNotAllowed();
        $this->get('/historias-clinicas/'.$paciente->historiaClinica->id)->assertNotFound();
        $this->assertDatabaseCount('antecedentes_clinicos', 1);
    }
}
