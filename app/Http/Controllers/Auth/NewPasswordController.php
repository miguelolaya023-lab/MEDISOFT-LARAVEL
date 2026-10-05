<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'email.required' => 'Ingresa tu correo electrónico.', 'email.email' => 'Ingresa un correo electrónico válido.',
            'password.required' => 'Ingresa una nueva contraseña.', 'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
            'token.required' => 'El enlace no es válido. Solicita uno nuevo.',
        ]);
        $credentials = [
            ...$validated, 'email' => Str::lower(trim($validated['email'])),
            'tipo_usuario' => [User::TIPO_USUARIO_INTERNO, User::TIPO_USUARIO_MEDICO],
        ];
        $status = DB::transaction(function () use ($credentials): string {
            DB::table(config('auth.passwords.'.config('auth.defaults.passwords').'.table'))
                ->where('email', $credentials['email'])->lockForUpdate()->first();

            return Password::reset($credentials, function (User $user, #[\SensitiveParameter] string $password): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                Auditoria::registrar('CONTRASENA_RESTABLECIDA', $user, 'Restablecimiento mediante enlace validado; estado y rol conservados.', $user->getKey());
                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $user->getKey())->delete();
                }
                event(new PasswordReset($user));
            });
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Contraseña restablecida. Inicia sesión con tu nueva contraseña; el estado de la cuenta se conserva.')
            : back()->withInput($request->only('email'))->withErrors(['email' => 'El enlace no es válido o ha caducado. Solicita uno nuevo.']);
    }
}
