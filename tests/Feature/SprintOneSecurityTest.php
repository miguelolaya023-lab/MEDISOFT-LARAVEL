<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SprintOneSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_audit_rolls_back_user_update(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = User::factory()->create();
        $email = $target->email;
        Auditoria::creating(function (): void {
            throw new RuntimeException('Fallo de auditoría simulado');
        });
        try {
            $this->actingAs($admin)->put(route('usuarios.update', $target), ['tipo_documento' => 'CC', 'numero_documento' => $target->numero_documento, 'nombres' => 'Cambio', 'apellidos' => 'Prueba', 'email' => 'cambio@example.com', 'telefono' => '3000000000', 'cargo' => 'Auxiliar'])->assertStatus(500);
            $this->assertSame($email, $target->refresh()->email);
        } finally {
            Auditoria::flushEventListeners();
        }
    }

    public function test_logout_requires_confirmation_in_navigation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('¿Cerrar sesión?');
    }

    public function test_bootstrap_assigns_only_first_admin_explicitly_and_audits(): void
    {
        $this->seed(SecuritySeeder::class);
        $user = User::factory()->create();
        $password = $user->password;
        $this->artisan('medisoft:bootstrap-admin', ['identificador' => $user->email])->assertSuccessful();
        $this->assertTrue($user->refresh()->puedeAdministrarUsuarios());
        $this->assertSame($password, $user->password);
        $this->artisan('medisoft:bootstrap-admin', ['identificador' => $user->email])->assertFailed();
        $this->assertDatabaseHas('auditorias', ['accion' => 'ROL_INICIAL_ASIGNADO', 'user_id' => null]);
    }

    private function datos(): array
    {
        return ['tipo_documento' => 'CC', 'numero_documento' => '0012345678', 'nombres' => 'Ana', 'apellidos' => 'Gomez', 'email' => 'ana@example.com', 'telefono' => '3001234567', 'cargo' => 'Auxiliar', 'estado' => 'activo', 'rol_id' => Rol::query()->where('nombre', 'Administrativo')->value('id')];
    }

    public function test_authorized_user_can_create_internal_user_with_existing_role(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->post('/usuarios', $this->datos())->assertSessionHasNoErrors()->assertRedirect();
        $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
        $this->assertSame('0012345678', $user->numero_documento);
        $this->assertSame($this->datos()['rol_id'], $user->rol_id);
        $this->assertDatabaseHas('auditorias', ['accion' => 'USUARIO_CREADO', 'registro_id' => $user->id, 'user_id' => $admin->id]);
    }

    public function test_user_without_permission_cannot_create_or_open_form(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/usuarios/create')->assertForbidden();
        $this->post('/usuarios', [])->assertForbidden();
    }

    public function test_creation_requires_existing_role_and_valid_state(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->post('/usuarios', [...$this->datos(), 'rol_id' => 999999, 'estado' => 'desconocido'])->assertInvalid(['rol_id', 'estado']);
    }

    public function test_creation_does_not_accept_arbitrary_permissions(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->post('/usuarios', [...$this->datos(), 'permisos' => ['RF-055']])->assertInvalid('permisos');
    }

    public function test_medical_profile_is_explicit_and_independent_of_role(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->post('/usuarios', [...$this->datos(), 'es_medico' => 1, 'registro_profesional' => 'RM-123', 'especialidad' => 'Medicina general'])->assertSessionHasNoErrors();
        $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
        $this->assertSame('RM-123', $user->medico->registro_profesional);
        $this->assertSame('Administrativo', $user->rol->nombre);
    }

    public function test_medical_fields_require_explicit_profile(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->post('/usuarios', [...$this->datos(), 'registro_profesional' => 'RM-123'])->assertInvalid('registro_profesional');
        $this->post('/usuarios', [...$this->datos(), 'es_medico' => 1])->assertInvalid(['registro_profesional', 'especialidad']);
    }

    public function test_update_rejects_other_accounts_identifiers_and_state_role_fields(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($admin)->put(route('usuarios.update', $target), [...$this->datos(), 'email' => $other->email, 'numero_documento' => $other->numero_documento])->assertInvalid(['email', 'numero_documento', 'estado', 'rol_id']);
    }

    public function test_update_and_query_require_permission(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $this->actingAs($user)->put(route('usuarios.update', $target), [])->assertForbidden();
        $this->get(route('usuarios.edit', $target))->assertForbidden();
        $this->get(route('usuarios.show', $target))->assertForbidden();
        $this->get('/usuarios')->assertForbidden();
    }

    public function test_search_selects_individual_record_without_secrets(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = User::factory()->create(['name' => 'Ana Gomez', 'numero_documento' => '00112233']);
        $this->actingAs($admin)->get('/usuarios?buscar=001122')->assertOk()->assertSee('Ana Gomez')->assertSee(route('usuarios.show', $target), false);
        $this->get('/usuarios?buscar=Ana')->assertOk()->assertSee('Ana Gomez');
        $this->get(route('usuarios.show', $target))->assertOk()->assertSee($target->email)->assertDontSee($target->password)->assertDontSee($target->remember_token);
        $this->get('/usuarios?buscar=inexistente')->assertSee('No se encontraron usuarios internos');
    }

    public function test_inactivation_requires_motive_and_preserves_account(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = User::factory()->create();
        $this->actingAs($admin)->patch(route('usuarios.inactivate', $target), ['motivo' => '   '])->assertInvalid('motivo');
        $this->assertSame('activo', $target->refresh()->estado);
        $this->patch(route('usuarios.inactivate', $target), ['motivo' => 'Salida del equipo'])->assertSessionHasNoErrors();
        $this->assertSame('inactivo', $target->refresh()->estado);
        $this->assertSame('Salida del equipo', $target->motivo_inactivacion);
        $this->assertDatabaseHas('auditorias', ['accion' => 'USUARIO_INACTIVADO', 'registro_id' => $target->id]);
    }

    public function test_inactive_account_cannot_use_existing_session(): void
    {
        $target = User::factory()->create(['estado' => 'inactivo']);
        $this->actingAs($target)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_existing_external_session_cannot_access_protected_operations(): void
    {
        $target = User::factory()->create(['tipo_usuario' => null]);
        $this->actingAs($target)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_reactivation_requires_permission_and_motive(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = User::factory()->create(['estado' => 'inactivo']);
        $this->actingAs($admin)->patch(route('usuarios.activate', $target), ['motivo' => 'Retorno autorizado'])->assertSessionHasNoErrors();
        $this->assertSame('activo', $target->refresh()->estado);
        $this->actingAs(User::factory()->create())->patch(route('usuarios.inactivate', $target), ['motivo' => 'Intento'])->assertForbidden();
    }

    public function test_last_administrator_cannot_be_inactivated_or_demoted(): void
    {
        $admin = User::factory()->administrator()->create();
        $role = Rol::query()->where('nombre', 'Administrativo')->firstOrFail();
        $this->actingAs($admin)->patch(route('usuarios.inactivate', $admin), ['motivo' => 'Prueba'])->assertInvalid('motivo');
        $this->patch(route('usuarios.assign-role', $admin), ['rol_id' => $role->id])->assertInvalid('rol_id');
        $this->assertTrue($admin->refresh()->puedeAdministrarUsuarios());
    }

    public function test_another_administrator_can_be_inactivated_when_one_remains(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = User::factory()->administrator()->create();
        $this->actingAs($admin)->patch(route('usuarios.inactivate', $target), ['motivo' => 'Prueba'])->assertSessionHasNoErrors();
        $this->assertSame('inactivo', $target->refresh()->estado);
    }

    public function test_role_assignment_requires_permission_and_applies_on_next_request(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = User::factory()->create();
        $this->actingAs($target)->patch(route('usuarios.assign-role', $target), ['rol_id' => $admin->rol_id])->assertForbidden();
        $this->actingAs($admin)->patch(route('usuarios.assign-role', $target), ['rol_id' => $admin->rol_id])->assertSessionHasNoErrors();
        $this->actingAs($target->refresh())->get('/usuarios')->assertOk();
        $this->assertDatabaseHas('auditorias', ['accion' => 'ROL_CAMBIADO', 'registro_id' => $target->id]);
    }

    public function test_cargo_does_not_grant_permissions(): void
    {
        $user = User::factory()->create(['cargo' => 'Administrador']);
        $this->actingAs($user)->get('/usuarios/create')->assertForbidden();
    }

    public function test_profile_route_cannot_bypass_update_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch('/profile', ['name' => 'Cambio', 'email' => 'cambio@example.com'])->assertForbidden();
    }

    public function test_old_session_is_rejected_after_administrator_inactivates_user(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = User::factory()->create();
        $this->actingAs($admin)->patch(route('usuarios.inactivate', $target), ['motivo' => 'Salida'])->assertSessionHasNoErrors();
        $this->actingAs($target->refresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }
}
