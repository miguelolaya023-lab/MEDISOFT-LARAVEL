<?php

namespace Tests\Feature;

use App\Models\AntecedenteClinico;
use App\Models\Auditoria;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\AntecedentePermissionsSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ExistingMedicoProfileTest extends TestCase
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

    private function target(string $estado = 'activo'): User
    {
        return User::factory()->create([
            'rol_id' => Rol::query()->firstOrCreate(['nombre' => 'Administrativo'])->id,
            'tipo_documento' => 'CC', 'nombres' => 'Persona', 'apellidos' => 'Ficticia',
            'name' => 'Persona Ficticia', 'telefono' => '003001234567', 'cargo' => 'Asistente', 'estado' => $estado,
        ])->refresh();
    }

    /** @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return ['registrar_perfil_medico' => '1', 'registro_profesional' => 'DEMO-0007', 'especialidad' => 'Medicina general', ...$cambios];
    }

    public static function estados(): array
    {
        return [['activo'], ['inactivo']];
    }

    #[DataProvider('estados')]
    public function test_profile_registration_preserves_all_account_attributes_role_permissions_and_history(string $estado): void
    {
        $admin = User::factory()->administrator()->create();
        $target = $this->target($estado);
        $antes = $target->getAttributes();
        $actorAntes = $admin->refresh()->getAttributes();
        $permisos = $target->rol->permisos()->pluck('id')->all();
        $previo = Auditoria::registrar('USUARIO_ACTUALIZADO', $target, 'Evento anterior ficticio.', $admin->id)->refresh();
        $auditAntes = $previo->getAttributes();
        $this->actingAs($admin)->get(route('usuarios.edit', $target))->assertOk()
            ->assertSee('Registrar perfil profesional Médico')->assertSee('no cambia el rol, no concede permisos')
            ->assertSee(route('usuarios.medico.store', $target), false)->assertSee('name="registrar_perfil_medico"', false);
        $this->post(route('usuarios.medico.store', $target), $this->datos(['registro_profesional' => '  DEMO-0007  ', 'especialidad' => '  Medicina general  ']))
            ->assertSessionHasNoErrors()->assertRedirect(route('usuarios.edit', $target))->assertSessionHas('status');
        $perfil = $target->medico()->sole();
        $this->assertSame($target->id, $perfil->user_id);
        $this->assertSame('DEMO-0007', $perfil->registro_profesional);
        $this->assertSame('Medicina general', $perfil->especialidad);
        $this->assertSame($antes, $target->refresh()->getAttributes());
        $this->assertSame($actorAntes, $admin->refresh()->getAttributes());
        $this->assertSame($permisos, $target->rol->permisos()->pluck('id')->all());
        $this->assertSame($auditAntes, $previo->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('medicos', 1);
        $audit = Auditoria::query()->where('accion', 'PERFIL_MEDICO_REGISTRADO')->sole();
        $this->assertSame('User', $audit->entidad);
        $this->assertSame($target->id, $audit->registro_id);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('EXITO', $audit->resultado);
        $this->assertNotNull($audit->fecha_hora);
        foreach (['DEMO-0007', 'Medicina general', $target->email, $target->numero_documento, $target->password] as $dato) {
            $this->assertStringNotContainsString($dato, $audit->descripcion);
        }
        $this->get(route('usuarios.edit', $target))->assertOk()->assertSee('DEMO-0007')->assertDontSee('name="registrar_perfil_medico"', false);
    }

    public function test_existing_profile_uses_original_editor_and_preserves_professional_identity_and_clinical_references(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = $this->target();
        $perfil = $target->medico()->create(['registro_profesional' => 'DEMO-ANTERIOR', 'especialidad' => 'Especialidad anterior'])->refresh();
        $original = $perfil->getAttributes();
        $registro = AntecedenteClinico::factory()->create(['medico_id' => $perfil->id])->refresh();
        $registroAntes = $registro->getAttributes();
        $this->actingAs($admin)->get(route('usuarios.edit', $target))->assertOk()->assertSee('DEMO-ANTERIOR')->assertDontSee('name="registrar_perfil_medico"', false);
        $this->put(route('usuarios.update', $target), [
            'tipo_documento' => $target->tipo_documento, 'numero_documento' => $target->numero_documento,
            'nombres' => $target->nombres, 'apellidos' => $target->apellidos, 'email' => $target->email,
            'telefono' => $target->telefono, 'cargo' => $target->cargo,
            'registro_profesional' => 'DEMO-CORREGIDO', 'especialidad' => 'Medicina general',
        ])->assertSessionHasNoErrors();
        $perfil->refresh();
        foreach (['id', 'user_id', 'created_at'] as $campo) {
            $this->assertSame($original[$campo], $perfil->getRawOriginal($campo));
        }
        $this->assertSame('DEMO-CORREGIDO', $perfil->registro_profesional);
        $this->assertSame($registroAntes, $registro->refresh()->getAttributes());
        $this->assertDatabaseCount('medicos', 1);
    }

    public function test_role_change_alone_does_not_create_profile_or_enable_rf058(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->seed(AntecedentePermissionsSeeder::class);
        $target = $this->target();
        $this->actingAs($admin)->patch(route('usuarios.assign-role', $target), ['rol_id' => Rol::query()->where('nombre', 'Médico')->value('id')])->assertSessionHasNoErrors();
        $this->assertNull($target->refresh()->medico);
        $this->assertTrue($target->tienePermiso('RF-058'));
        $paciente = Paciente::factory()->create();
        $paciente->historiaClinica()->create(['fecha_apertura' => now()]);
        $this->actingAs($target)->get(route('antecedentes.index', $paciente))->assertForbidden();
        $this->assertDatabaseCount('medicos', 0);
    }

    public function test_rf058_still_requires_permission_and_profile_and_role_changes_keep_the_profile(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->seed(AntecedentePermissionsSeeder::class);
        $target = $this->target();
        $paciente = Paciente::factory()->create();
        $paciente->historiaClinica()->create(['fecha_apertura' => now()]);
        $this->actingAs($admin)->post(route('usuarios.medico.store', $target), $this->datos())->assertSessionHasNoErrors();
        $perfilId = $target->medico()->sole()->id;
        $this->actingAs($target->refresh())->get(route('antecedentes.index', $paciente))->assertForbidden();
        $this->actingAs($admin)->patch(route('usuarios.assign-role', $target), ['rol_id' => Rol::query()->where('nombre', 'Médico')->value('id')])->assertSessionHasNoErrors();
        $this->actingAs($target->refresh())->get(route('antecedentes.index', $paciente))->assertOk();
        $this->actingAs($admin)->patch(route('usuarios.assign-role', $target), ['rol_id' => Rol::query()->where('nombre', 'Administrativo')->value('id')])->assertSessionHasNoErrors();
        $this->assertSame($perfilId, $target->medico()->sole()->id);
        $this->actingAs($target->refresh())->get(route('antecedentes.index', $paciente))->assertForbidden();
    }

    public function test_rf005_alone_authorizes_profile_creation_without_rf006_rf004_or_rf055(): void
    {
        $this->seed(SecuritySeeder::class);
        $rol = Rol::query()->create(['nombre' => 'Edición de cuentas']);
        $rol->permisos()->attach(Permiso::query()->where('codigo', 'RF-005')->value('id'));
        $actor = User::factory()->create(['rol_id' => $rol->id]);
        $target = $this->target();
        $this->actingAs($actor)->post(route('usuarios.medico.store', $target), $this->datos())->assertSessionHasNoErrors()->assertRedirect(route('usuarios.edit', $target));
        $this->get(route('usuarios.edit', $target))->assertOk();
        $this->get(route('usuarios.show', $target))->assertForbidden();
        $this->patch(route('usuarios.assign-role', $target), ['rol_id' => $rol->id])->assertForbidden();
    }

    public static function permisosInsuficientes(): array
    {
        return [[[]], [['RF-004']], [['RF-055']], [['RF-006']]];
    }

    #[DataProvider('permisosInsuficientes')]
    public function test_no_administrative_update_permission_returns_403_even_with_administrator_cargo(array $permisos): void
    {
        $this->seed(SecuritySeeder::class);
        $rol = Rol::query()->create(['nombre' => 'Sin RF005 '.uniqid()]);
        $rol->permisos()->sync(Permiso::query()->whereIn('codigo', $permisos)->pluck('id'));
        $actor = User::factory()->create(['rol_id' => $rol->id, 'cargo' => 'Administrador']);
        $target = $this->target();
        $this->actingAs($actor)->post(route('usuarios.medico.store', $target), $this->datos())->assertForbidden();
        $this->get(route('usuarios.edit', $target))->assertForbidden();
        $this->assertDatabaseCount('medicos', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_guests_inactive_actors_and_non_internal_targets_cannot_create_profile(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = $this->target();
        $this->post(route('usuarios.medico.store', $target), $this->datos())->assertRedirect('/login');
        $admin->update(['estado' => 'inactivo']);
        $this->actingAs($admin)->post(route('usuarios.medico.store', $target), $this->datos())->assertRedirect('/login');
        $this->assertGuest();
        $admin->update(['estado' => 'activo']);
        $target->update(['tipo_usuario' => 'externo']);
        $this->actingAs($admin)->post(route('usuarios.medico.store', $target), $this->datos())->assertForbidden();
        $this->assertDatabaseCount('medicos', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function entradasInvalidas(): array
    {
        return [
            ['registrar_perfil_medico', null], ['registrar_perfil_medico', '0'], ['registrar_perfil_medico', ['1']],
            ['registro_profesional', null], ['registro_profesional', '  '], ['registro_profesional', ['DEMO']],
            ['registro_profesional', str_repeat('a', 101)], ['especialidad', null], ['especialidad', '   '],
            ['especialidad', ['General']], ['especialidad', str_repeat('a', 101)],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_profile_requires_explicit_intent_and_valid_professional_fields(string $campo, mixed $valor): void
    {
        $admin = User::factory()->administrator()->create();
        $target = $this->target();
        $before = $target->getAttributes();
        $this->actingAs($admin)->post(route('usuarios.medico.store', $target), $this->datos([$campo => $valor]))
            ->assertRedirect(route('usuarios.edit', $target))->assertInvalid($campo);
        $this->assertSame($before, $target->refresh()->getAttributes());
        $this->assertDatabaseCount('medicos', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function camposManipulados(): array
    {
        return [['user_id', 999], ['user_id', null], ['medico_id', 999], ['id', 999], ['rol_id', 999], ['estado', 'activo'], ['estado', null], ['permisos', []], ['permissions', ['RF-058']], ['password', 'ficticio'], ['tipo_usuario', 'medico'], ['numero_documento', '0000000'], ['email', 'ficticio@example.com'], ['created_at', '2000-01-01'], ['es_medico', 1]];
    }

    #[DataProvider('camposManipulados')]
    public function test_only_professional_fields_and_explicit_intent_are_accepted(string $campo, mixed $valor): void
    {
        $admin = User::factory()->administrator()->create();
        $target = $this->target();
        $before = $target->getAttributes();
        $this->actingAs($admin)->post(route('usuarios.medico.store', $target), $this->datos([$campo => $valor]))->assertInvalid($campo);
        $this->assertSame($before, $target->refresh()->getAttributes());
        $this->assertDatabaseCount('medicos', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_duplicate_submission_preserves_original_profile_and_does_not_add_audit(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = $this->target();
        $this->actingAs($admin)->post(route('usuarios.medico.store', $target), $this->datos())->assertSessionHasNoErrors();
        $original = $target->medico()->sole()->getAttributes();
        $this->post(route('usuarios.medico.store', $target), $this->datos(['registro_profesional' => 'OTRO-DEMO']))
            ->assertRedirect(route('usuarios.edit', $target))->assertInvalid('registrar_perfil_medico');
        $sessionId = session()->getId();
        $this->assertSame($original, $target->medico()->sole()->getAttributes());
        $this->assertDatabaseCount('medicos', 1);
        $this->assertDatabaseCount('auditorias', 1);
        $this->withCookie(config('session.cookie'), $sessionId)->get(route('usuarios.edit', $target))->assertOk()
            ->assertSee('El usuario ya tiene un perfil profesional Médico.')
            ->assertSee('value="DEMO-0007"', false)->assertDontSee('OTRO-DEMO');
    }

    public function test_profile_appearing_after_validation_is_detected_under_user_lock(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = $this->target();
        $this->actingAs($admin);
        $insertado = false;
        DB::listen(function (QueryExecuted $query) use ($target, &$insertado): void {
            if (! $insertado && str_contains($query->sql, 'select exists') && str_contains($query->sql, '"medicos"') && in_array($target->id, $query->bindings, true)) {
                $insertado = true;
                $target->medico()->create(['registro_profesional' => 'DEMO-CONCURRENTE', 'especialidad' => 'Medicina general']);
            }
        });
        $this->post(route('usuarios.medico.store', $target), $this->datos())->assertInvalid('registrar_perfil_medico');
        $this->assertTrue($insertado);
        $this->assertSame('DEMO-CONCURRENTE', $target->medico()->sole()->registro_profesional);
        $this->assertDatabaseCount('medicos', 1);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function etapasFallo(): array
    {
        return [['perfil'], ['auditoria']];
    }

    #[DataProvider('etapasFallo')]
    public function test_profile_and_audit_failures_roll_back_every_write(string $etapa): void
    {
        $admin = User::factory()->administrator()->create();
        $target = $this->target();
        $before = $target->getAttributes();
        $fallo = static function (): void {
            throw new RuntimeException('Fallo ficticio del alta profesional');
        };
        if ($etapa === 'perfil') {
            Medico::created($fallo);
        } else {
            Auditoria::created($fallo);
        }
        $this->actingAs($admin)->withoutExceptionHandling();
        try {
            $this->post(route('usuarios.medico.store', $target), $this->datos());
            $this->fail('Se esperaba fallo de persistencia.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo ficticio del alta profesional', $exception->getMessage());
            $this->assertSame($before, $target->refresh()->getAttributes());
            $this->assertDatabaseCount('medicos', 0);
            $this->assertDatabaseCount('auditorias', 0);
        } finally {
            Medico::flushEventListeners();
            Auditoria::flushEventListeners();
        }
    }

    public function test_unknown_target_returns_404_and_professional_lengths_match_existing_schema(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->post('/usuarios/999999/perfil-medico', $this->datos())->assertNotFound();
        $target = $this->target();
        $this->post(route('usuarios.medico.store', $target), $this->datos(['registro_profesional' => str_repeat('a', 100), 'especialidad' => str_repeat('b', 100)]))->assertSessionHasNoErrors();
        $this->assertSame(100, strlen($target->medico()->sole()->registro_profesional));
    }
}
