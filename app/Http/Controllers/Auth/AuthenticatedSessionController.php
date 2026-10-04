<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        try {
            DB::transaction(function () use ($request): void {
                $user = $request->user();
                $user->update(['ultimo_acceso' => now()]);
                Auditoria::registrar('SESION_INICIADA', $user, 'Acceso interno confirmado.');
            });
        } catch (Throwable $exception) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            report($exception);
            throw ValidationException::withMessages(['identificador' => trans('auth.failed')]);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if ($user) {
            try {
                Auditoria::registrar('SESION_CERRADA', $user, 'Salida confirmada.', $user->id);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return redirect()->route('login');
    }
}
