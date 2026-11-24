<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && (! $request->user()->estaActivo() || ! $request->user()->esInterno() || ! $request->user()->rol_id)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $response = redirect()->route('login');

            if ($request->routeIs('pacientes.historia.show', 'pacientes.historial.*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }

            return $response;
        }

        return $next($request);
    }
}
