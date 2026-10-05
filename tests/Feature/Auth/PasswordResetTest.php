<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    private function resetData(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'NuevaClave2026!', 'password_confirmation' => 'NuevaClave2026!'];
    }

    public function test_existing_unknown_and_throttled_emails_receive_same_generic_response_without_token(): void
    {
        $user = User::factory()->create();
        Notification::fake();
        foreach ([$user->email, 'ausente@example.com', $user->email] as $email) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])
                ->assertSessionHasNoErrors()->assertSessionHas('status', PasswordResetLinkController::GENERIC_STATUS);
        }
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $notification = Notification::sent($user, ResetPassword::class)->sole();
        $this->get('/forgot-password')->assertOk()->assertDontSee($notification->token);
        $this->assertTrue(Hash::check($notification->token, DB::table('password_reset_tokens')->where('email', $user->email)->value('token')));
    }

    public function test_recovery_mail_uses_configured_app_url_instead_of_request_host(): void
    {
        $user = User::factory()->create();
        Notification::fake();
        config(['app.url' => 'https://medisoft.example.test']);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        $notification = Notification::sent($user, ResetPassword::class)->sole();
        $mail = $notification->toMail($user);
        $this->assertSame('https://medisoft.example.test/reset-password/'.$notification->token.'?email='.urlencode($user->email), $mail->actionUrl);
        $this->assertSame('Restablecer contraseña', $mail->actionText);
        $this->assertSame('Recuperar contraseña de MEDISOFT', $mail->subject);
    }

    public function test_recovery_email_html_and_plain_text_are_in_spanish_and_branded_medisoft(): void
    {
        $user = User::factory()->create();
        config(['app.name' => 'Laravel', 'mail.from.name' => 'Laravel']);
        $mail = (new ResetPassword('token-de-prueba'))->toMail($user);
        $this->assertSame([config('mail.from.address'), 'MEDISOFT'], $mail->from);
        $renderer = app(Markdown::class);
        foreach ([(string) $mail->render(), (string) $renderer->renderText($mail->markdown, $mail->data())] as $contenido) {
            $this->assertStringContainsString('MEDISOFT', $contenido);
            $this->assertStringContainsString('Restablecer contraseña', $contenido);
            $this->assertStringContainsString('Recibimos una solicitud', $contenido);
            $this->assertStringContainsString('60 minutos', $contenido);
            $this->assertStringContainsString('solo puede utilizarse una vez', $contenido);
            $this->assertStringContainsString('Si no solicitaste este cambio, ignora el mensaje.', $contenido);
            $this->assertStringContainsString('Saludos,', $contenido);
            $this->assertStringContainsString('copia y pega la siguiente URL en tu navegador', $contenido);
            $this->assertStringContainsString('Todos los derechos reservados.', $contenido);
            $this->assertStringContainsString($mail->actionUrl, html_entity_decode($contenido));
            $this->assertStringNotContainsString('Laravel', $contenido);
            $this->assertStringNotContainsString('Regards,', $contenido);
            $this->assertStringNotContainsString("If you're having trouble", $contenido);
            $this->assertStringNotContainsString('All rights reserved.', $contenido);
        }
    }

    public function test_reset_changes_password_rotates_remember_token_and_audits_without_secrets(): void
    {
        $user = User::factory()->create();
        $remember = $user->remember_token;
        $role = $user->rol_id;
        $token = Password::createToken($user);
        $this->post('/reset-password', $this->resetData($user, $token))->assertSessionHasNoErrors()->assertRedirect('/login');
        $user->refresh();
        $this->assertTrue(Hash::check('NuevaClave2026!', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertNotSame($remember, $user->remember_token);
        $this->assertSame($role, $user->rol_id);
        $this->assertSame('activo', $user->estado);
        $this->assertFalse(Password::tokenExists($user, $token));
        $audit = Auditoria::query()->sole();
        $this->assertSame('CONTRASENA_RESTABLECIDA', $audit->accion);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame($user->id, $audit->registro_id);
        $this->assertSame('User', $audit->entidad);
        foreach ([$token, 'NuevaClave2026!', $user->password, $user->remember_token] as $secreto) {
            $this->assertStringNotContainsString($secreto, $audit->toJson());
        }
    }

    public function test_active_user_can_login_by_email_or_document_with_new_password(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $this->post('/reset-password', $this->resetData($user, $token))->assertSessionHasNoErrors();
        $this->post('/login', ['identificador' => $user->email, 'password' => 'password'])->assertInvalid('identificador');
        foreach ([$user->email, $user->numero_documento] as $identificador) {
            $this->post('/login', ['identificador' => $identificador, 'password' => 'NuevaClave2026!'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
            $this->assertAuthenticatedAs($user);
            $this->post('/logout')->assertRedirect('/login');
        }
    }

    public function test_inactive_user_can_reset_but_remains_inactive_with_same_role_and_no_access(): void
    {
        $user = User::factory()->create(['estado' => 'inactivo']);
        $role = $user->rol_id;
        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        $token = Notification::sent($user, ResetPassword::class)->sole()->token;
        $this->post('/reset-password', $this->resetData($user, $token))->assertSessionHasNoErrors();
        $this->assertSame('inactivo', $user->refresh()->estado);
        $this->assertSame($role, $user->rol_id);
        $this->post('/login', ['identificador' => $user->email, 'password' => 'NuevaClave2026!'])->assertInvalid('identificador');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_invalid_expired_and_used_tokens_cannot_change_password(): void
    {
        $user = User::factory()->create();
        $original = $user->password;
        $this->post('/reset-password', $this->resetData($user, 'invalido'))->assertInvalid('email');
        $token = Password::createToken($user);
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
        $this->post('/reset-password', $this->resetData($user, $token))->assertInvalid('email');
        $this->assertSame($original, $user->refresh()->password);
        $this->travelBack();
        $token = Password::createToken($user);
        $this->post('/reset-password', $this->resetData($user, $token))->assertSessionHasNoErrors();
        $password = $user->refresh()->password;
        $this->post('/reset-password', $this->resetData($user, $token))->assertInvalid('email');
        $this->assertSame($password, $user->refresh()->password);
        $this->assertDatabaseCount('auditorias', 1);
    }

    public function test_password_confirmation_and_minimum_length_remain_required(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $this->post('/reset-password', [...$this->resetData($user, $token), 'password_confirmation' => 'distinta'])->assertInvalid('password');
        $this->post('/reset-password', [...$this->resetData($user, $token), 'password' => 'abc', 'password_confirmation' => 'abc'])->assertInvalid('password');
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_reset_cannot_be_used_for_non_internal_accounts(): void
    {
        $user = User::factory()->create(['tipo_usuario' => null]);
        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', PasswordResetLinkController::GENERIC_STATUS);
        Notification::assertNothingSent();
        $token = Password::createToken($user);
        $this->post('/reset-password', $this->resetData($user, $token))->assertInvalid('email');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_audit_failure_rolls_back_password_remember_token_and_token_consumption(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $original = $user->password;
        $remember = $user->remember_token;
        $this->withoutExceptionHandling();
        Auditoria::creating(function (): void {
            throw new RuntimeException('Fallo de auditoría simulado');
        });
        try {
            $this->post('/reset-password', $this->resetData($user, $token));
            $this->fail('Se esperaba fallo de auditoría.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo de auditoría simulado', $exception->getMessage());
            $this->assertSame($original, $user->refresh()->password);
            $this->assertSame($remember, $user->remember_token);
            $this->assertTrue(Password::tokenExists($user, $token));
            $this->assertDatabaseCount('auditorias', 0);
        } finally {
            Auditoria::flushEventListeners();
        }
    }

    public function test_reset_revokes_only_affected_users_database_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = Password::createToken($user);
        foreach (['sesion-afectada' => $user->id, 'sesion-ajena' => $other->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => base64_encode('{}'), 'last_activity' => now()->timestamp]);
        }
        config(['session.driver' => 'database']);
        $this->post('/reset-password', $this->resetData($user, $token))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-afectada']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-ajena']);
    }

    public function test_request_keeps_broker_throttle_and_enforces_ip_rate_limit(): void
    {
        $user = User::factory()->create();
        Notification::fake();
        for ($i = 0; $i < 6; $i++) {
            $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        }
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $this->post('/forgot-password', ['email' => $user->email])->assertStatus(429);
    }

    public function test_reset_attempts_are_rate_limited_even_with_invalid_tokens(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 6; $i++) {
            $this->post('/reset-password', $this->resetData($user, 'invalido'))->assertInvalid('email');
        }
        $this->post('/reset-password', $this->resetData($user, 'invalido'))->assertStatus(429);
    }

    public function test_transport_failure_is_logged_without_secrets_and_allows_retry(): void
    {
        $user = User::factory()->create();
        Notification::shouldReceive('send')->once()->andThrow(new TransportException('Fallo simulado con detalle sensible que no debe registrarse'));
        Log::spy();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors()->assertSessionHas('status', PasswordResetLinkController::GENERIC_STATUS);
        Log::shouldHaveReceived('error')->once()->with('RF-003: fallo del transporte de correo; solicitud pendiente de reintento.');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_own_recovery_screens_are_in_spanish(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Enviar enlace de recuperación')->assertSee('Correo electrónico');
        $this->get('/reset-password/token-de-prueba')->assertOk()->assertSee('Nueva contraseña')->assertSee('Confirmar contraseña')->assertSee('Restablecer contraseña');
    }
}
