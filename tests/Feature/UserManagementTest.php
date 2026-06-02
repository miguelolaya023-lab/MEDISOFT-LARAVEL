<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/usuarios')->assertRedirect('/login');
        $this->get('/usuarios/create')->assertRedirect('/login');
        $this->post('/usuarios', [])->assertRedirect('/login');
        $this->get('/usuarios/1/edit')->assertRedirect('/login');
        $this->put('/usuarios/1', [])->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_create_form(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/usuarios/create');

        $response
            ->assertOk()
            ->assertSee('Crear usuario')
            ->assertSee('Guardar usuario');
    }

    public function test_internal_user_creation_requires_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/usuarios/create')
            ->post('/usuarios', []);

        $response
            ->assertRedirect('/usuarios/create')
            ->assertInvalid([
                'tipo_documento',
                'numero_documento',
                'nombres',
                'apellidos',
                'email',
                'telefono',
                'cargo',
                'estado',
            ]);
    }

    public function test_internal_user_creation_requires_unique_document_and_email(): void
    {
        $user = User::factory()->create();

        User::factory()->create([
            'email' => 'existente@example.com',
            'numero_documento' => '123456789',
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/usuarios/create')
            ->post('/usuarios', [
                ...$this->validUserData(),
                'email' => 'existente@example.com',
                'numero_documento' => '123456789',
            ]);

        $response
            ->assertRedirect('/usuarios/create')
            ->assertInvalid(['email', 'numero_documento']);
    }

    public function test_authenticated_user_can_store_internal_user(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post('/usuarios', $this->validUserData());

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHas('status', 'Usuario creado correctamente.');

        $this->assertDatabaseHas('users', [
            'name' => 'Ana Maria Gomez Rios',
            'tipo_documento' => 'CC',
            'numero_documento' => '987654321',
            'nombres' => 'Ana Maria',
            'apellidos' => 'Gomez Rios',
            'email' => 'ana.gomez@example.com',
            'telefono' => '3001234567',
            'cargo' => 'Auxiliar administrativo',
            'estado' => 'activo',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);

        $createdUser = User::query()
            ->where('email', 'ana.gomez@example.com')
            ->firstOrFail();

        $this->assertNotEmpty($createdUser->password);

        $this
            ->actingAs($user)
            ->get('/usuarios')
            ->assertOk()
            ->assertSee('Ana Maria Gomez Rios')
            ->assertSee('Usuario creado correctamente.', false);
    }

    public function test_users_list_only_displays_internal_users(): void
    {
        $user = User::factory()->create();

        $publicUser = User::factory()->create([
            'name' => 'Usuario Registro Publico',
            'email' => 'registro.publico@example.com',
            'tipo_usuario' => null,
        ]);

        $doctorUser = User::factory()->create([
            'name' => 'Medico Principal',
            'email' => 'medico@example.com',
            'tipo_usuario' => User::TIPO_USUARIO_MEDICO,
        ]);

        $internalUser = User::factory()->create([
            'name' => 'Usuario Interno',
            'email' => 'interno@example.com',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/usuarios');

        $response
            ->assertOk()
            ->assertSee($internalUser->name)
            ->assertDontSee($publicUser->name)
            ->assertDontSee($doctorUser->name);
    }

    public function test_authenticated_user_can_view_internal_user_edit_form(): void
    {
        $user = User::factory()->create();
        $internalUser = User::factory()->create([
            ...$this->validUserData(),
            'name' => 'Ana Maria Gomez Rios',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('usuarios.edit', $internalUser));

        $response
            ->assertOk()
            ->assertSee('Editar usuario')
            ->assertSee('Ana Maria')
            ->assertSee('Guardar cambios');
    }

    public function test_authenticated_user_can_update_internal_user(): void
    {
        $user = User::factory()->create();
        $internalUser = User::factory()->create([
            ...$this->validUserData(),
            'name' => 'Ana Maria Gomez Rios',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);
        $originalPassword = $internalUser->password;

        $response = $this
            ->actingAs($user)
            ->put(route('usuarios.update', $internalUser), [
                ...$this->validUserData(),
                'numero_documento' => '1122334455',
                'nombres' => 'Laura',
                'apellidos' => 'Martinez Silva',
                'email' => 'LAURA.MARTINEZ@example.com',
                'telefono' => '3112223344',
                'cargo' => 'Recepcionista',
                'estado' => 'inactivo',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHas('status', 'Usuario actualizado correctamente.');

        $internalUser->refresh();

        $this->assertSame('Laura Martinez Silva', $internalUser->name);
        $this->assertSame('laura.martinez@example.com', $internalUser->email);
        $this->assertSame('1122334455', $internalUser->numero_documento);
        $this->assertSame('Recepcionista', $internalUser->cargo);
        $this->assertSame('activo', $internalUser->estado);
        $this->assertSame(User::TIPO_USUARIO_INTERNO, $internalUser->tipo_usuario);
        $this->assertSame($originalPassword, $internalUser->password);
    }

    public function test_internal_user_update_requires_unique_document_and_email_except_current_user(): void
    {
        $user = User::factory()->create();
        $internalUser = User::factory()->create([
            ...$this->validUserData(),
            'name' => 'Ana Maria Gomez Rios',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);

        $otherUser = User::factory()->create([
            ...$this->validUserData(),
            'name' => 'Carlos Perez',
            'email' => 'carlos.perez@example.com',
            'numero_documento' => '555666777',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);

        $sameDataResponse = $this
            ->actingAs($user)
            ->from(route('usuarios.edit', $internalUser))
            ->put(route('usuarios.update', $internalUser), $this->validUserData());

        $sameDataResponse
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('usuarios.index'));

        $duplicateResponse = $this
            ->actingAs($user)
            ->from(route('usuarios.edit', $internalUser))
            ->put(route('usuarios.update', $internalUser), [
                ...$this->validUserData(),
                'email' => $otherUser->email,
                'numero_documento' => $otherUser->numero_documento,
            ]);

        $duplicateResponse
            ->assertRedirect(route('usuarios.edit', $internalUser))
            ->assertInvalid(['email', 'numero_documento']);
    }

    public function test_non_internal_users_cannot_be_edited_from_users_module(): void
    {
        $user = User::factory()->create();
        $publicUser = User::factory()->create([
            'tipo_usuario' => null,
        ]);
        $doctorUser = User::factory()->create([
            'tipo_usuario' => User::TIPO_USUARIO_MEDICO,
        ]);

        $this
            ->actingAs($user)
            ->get(route('usuarios.edit', $publicUser))
            ->assertNotFound();

        $this
            ->actingAs($user)
            ->get(route('usuarios.edit', $doctorUser))
            ->assertNotFound();

        $this
            ->actingAs($user)
            ->put(route('usuarios.update', $publicUser), $this->validUserData())
            ->assertForbidden();
    }

    /**
     * @return array<string, string>
     */
    private function validUserData(): array
    {
        return [
            'tipo_documento' => 'CC',
            'numero_documento' => '987654321',
            'nombres' => 'Ana Maria',
            'apellidos' => 'Gomez Rios',
            'email' => 'ana.gomez@example.com',
            'telefono' => '3001234567',
            'cargo' => 'Auxiliar administrativo',
            'estado' => 'activo',
        ];
    }
}
