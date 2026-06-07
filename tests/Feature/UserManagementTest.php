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
        $this->patch('/usuarios/1/inactivar')->assertRedirect('/login');
        $this->patch('/usuarios/1/activar')->assertRedirect('/login');
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

    public function test_users_list_can_search_internal_users_by_partial_criteria(): void
    {
        $user = User::factory()->create();

        User::factory()->create([
            ...$this->validUserData(),
            'name' => 'Miguel Olaya',
            'email' => 'miguel.olaya@example.com',
            'numero_documento' => '1118123456',
            'nombres' => 'Miguel',
            'apellidos' => 'Olaya',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);
        User::factory()->create([
            ...$this->validUserData(),
            'name' => 'Carlos Perez',
            'email' => 'carlos.perez@example.com',
            'numero_documento' => '99990000',
            'nombres' => 'Carlos',
            'apellidos' => 'Perez',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);
        User::factory()->create([
            ...$this->validUserData(),
            'name' => 'Miguel Olaya Externo',
            'email' => 'miguel.externo@example.com',
            'numero_documento' => '11189999',
            'nombres' => 'Miguel',
            'apellidos' => 'Olaya Externo',
            'tipo_usuario' => User::TIPO_USUARIO_MEDICO,
        ]);

        $fullNameResponse = $this
            ->actingAs($user)
            ->get(route('usuarios.index', ['buscar' => 'Miguel Olaya']));

        $fullNameResponse
            ->assertOk()
            ->assertSee('Miguel Olaya')
            ->assertDontSee('Carlos Perez')
            ->assertDontSee('Miguel Olaya Externo');

        $documentResponse = $this
            ->actingAs($user)
            ->get(route('usuarios.index', ['buscar' => '1118']));

        $documentResponse
            ->assertOk()
            ->assertSee('Miguel Olaya')
            ->assertDontSee('Carlos Perez')
            ->assertDontSee('Miguel Olaya Externo');

        $lastNameResponse = $this
            ->actingAs($user)
            ->get(route('usuarios.index', ['buscar' => 'Ola']));

        $lastNameResponse
            ->assertOk()
            ->assertSee('Miguel Olaya')
            ->assertDontSee('Carlos Perez')
            ->assertDontSee('Miguel Olaya Externo');
    }

    public function test_users_list_search_keeps_filter_on_pagination_links(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 11; $i++) {
            User::factory()->create([
                ...$this->validUserData(),
                'name' => "Busqueda Interno {$i}",
                'email' => "busqueda.interno{$i}@example.com",
                'numero_documento' => "12345{$i}",
                'nombres' => 'Busqueda',
                'apellidos' => "Interno {$i}",
                'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
            ]);
        }

        $response = $this
            ->actingAs($user)
            ->get(route('usuarios.index', ['buscar' => 'Busqueda']));

        $response
            ->assertOk()
            ->assertSee('buscar=Busqueda', false);
    }

    public function test_users_list_shows_search_empty_state_message(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('usuarios.index', ['buscar' => 'sin coincidencias']));

        $response
            ->assertOk()
            ->assertSee('No se encontraron usuarios internos con el criterio de búsqueda ingresado.');
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

    public function test_authenticated_user_can_inactivate_internal_user(): void
    {
        $user = User::factory()->create();
        $internalUser = User::factory()->create([
            ...$this->validUserData(),
            'name' => 'Ana Maria Gomez Rios',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('usuarios.inactivate', $internalUser));

        $response
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHas('status', 'Usuario inactivado correctamente.');

        $internalUser->refresh();

        $this->assertSame('inactivo', $internalUser->estado);
        $this->assertSame(User::TIPO_USUARIO_INTERNO, $internalUser->tipo_usuario);
        $this->assertDatabaseHas('users', [
            'id' => $internalUser->id,
            'estado' => 'inactivo',
        ]);
    }

    public function test_users_list_shows_state_action_buttons_for_internal_users(): void
    {
        $user = User::factory()->create();
        $activeUser = User::factory()->create([
            ...$this->validUserData(),
            'name' => 'Usuario Activo',
            'email' => 'activo@example.com',
            'numero_documento' => '111222333',
            'estado' => 'activo',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);
        $inactiveUser = User::factory()->create([
            ...$this->validUserData(),
            'name' => 'Usuario Inactivo',
            'email' => 'inactivo@example.com',
            'numero_documento' => '444555666',
            'estado' => 'inactivo',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('usuarios.index'));

        $response
            ->assertOk()
            ->assertSee(route('usuarios.inactivate', $activeUser), false)
            ->assertDontSee(route('usuarios.activate', $activeUser), false)
            ->assertDontSee(route('usuarios.inactivate', $inactiveUser), false)
            ->assertSee(route('usuarios.activate', $inactiveUser), false);
    }

    public function test_authenticated_user_cannot_inactivate_itself(): void
    {
        $user = User::factory()->create([
            ...$this->validUserData(),
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('usuarios.inactivate', $user));

        $response->assertForbidden();

        $this->assertSame('activo', $user->refresh()->estado);
    }

    public function test_non_internal_users_cannot_be_inactivated_from_users_module(): void
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
            ->patch(route('usuarios.inactivate', $publicUser))
            ->assertNotFound();

        $this
            ->actingAs($user)
            ->patch(route('usuarios.inactivate', $doctorUser))
            ->assertNotFound();
    }

    public function test_authenticated_user_can_activate_internal_user(): void
    {
        $user = User::factory()->create();
        $internalUser = User::factory()->create([
            ...$this->validUserData(),
            'name' => 'Ana Maria Gomez Rios',
            'estado' => 'inactivo',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('usuarios.activate', $internalUser));

        $response
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHas('status', 'Usuario activado correctamente.');

        $internalUser->refresh();

        $this->assertSame('activo', $internalUser->estado);
        $this->assertSame(User::TIPO_USUARIO_INTERNO, $internalUser->tipo_usuario);
        $this->assertDatabaseHas('users', [
            'id' => $internalUser->id,
            'estado' => 'activo',
        ]);
    }

    public function test_non_internal_users_cannot_be_activated_from_users_module(): void
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
            ->patch(route('usuarios.activate', $publicUser))
            ->assertNotFound();

        $this
            ->actingAs($user)
            ->patch(route('usuarios.activate', $doctorUser))
            ->assertNotFound();
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
