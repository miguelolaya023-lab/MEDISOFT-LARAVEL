<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetLinkController extends Controller
{
    public const GENERIC_STATUS = 'Solicitud procesada. Si el correo corresponde a una cuenta interna y el servicio está disponible, recibirás un enlace de recuperación. Si no llega, espera un minuto antes de volver a solicitarlo.';

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']], [
            'email.required' => 'Ingresa tu correo electrónico.',
            'email.email' => 'Ingresa un correo electrónico válido.',
        ]);
        $credentials = [
            'email' => Str::lower(trim($validated['email'])),
            'tipo_usuario' => [User::TIPO_USUARIO_INTERNO, User::TIPO_USUARIO_MEDICO],
        ];
        Password::sendResetLink($credentials, function (User $user, #[\SensitiveParameter] string $token): void {
            try {
                $user->sendPasswordResetNotification($token);
            } catch (TransportExceptionInterface) {
                Password::deleteToken($user);
                Log::error('RF-003: fallo del transporte de correo; solicitud pendiente de reintento.');
            }
        });

        return back()->with('status', self::GENERIC_STATUS);
    }
}
