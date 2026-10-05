<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        ResetPassword::toMailUsing(function (User $user, #[\SensitiveParameter] string $token): MailMessage {
            $url = rtrim(config('app.url'), '/').route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false);

            return (new MailMessage)->markdown('mail.password-reset')
                ->from(config('mail.from.address'), 'MEDISOFT')
                ->subject('Recuperar contraseña de MEDISOFT')->greeting('Hola')
                ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta interna.')
                ->action('Restablecer contraseña', $url)
                ->line('Este enlace caduca en '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutos y solo puede utilizarse una vez.')
                ->line('Si no solicitaste este cambio, ignora el mensaje.');
        });
    }
}
