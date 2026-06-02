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
