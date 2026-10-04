<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Correo electrónico');
        $response->assertSee('Contraseña');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect(route('login'));
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_logout_is_not_available_using_get(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/logout');

        $response->assertMethodNotAllowed();
        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }

    public function test_user_can_login_using_document_with_leading_zeroes(): void
    {
        $user = User::factory()->create(['numero_documento' => '001234']);
        $this->post('/login', ['identificador' => '001234', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->ultimo_acceso);
    }

    public function test_inactive_and_invalid_credentials_share_generic_rejection(): void
    {
        $user = User::factory()->create(['estado' => 'inactivo']);
        $this->post('/login', ['identificador' => $user->email, 'password' => 'password'])->assertInvalid(['identificador' => trans('auth.failed')]);
        $this->assertGuest();
        $this->post('/login', ['identificador' => 'desconocido', 'password' => 'password'])->assertInvalid(['identificador' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_login_preserves_five_attempt_rate_limit(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['identificador' => $user->email, 'password' => 'incorrecta'])->assertInvalid('identificador');
        }
        $this->post('/login', ['identificador' => $user->email, 'password' => 'password'])->assertInvalid('identificador');
        $this->assertGuest();
    }
}
