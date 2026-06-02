<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * Display the internal users list.
     */
    public function index(): View
    {
        return view('usuarios.index', [
            'usuarios' => User::query()
                ->where('tipo_usuario', User::TIPO_USUARIO_INTERNO)
                ->latest()
                ->paginate(10),
        ]);
    }

    /**
     * Show the form for creating an internal user.
     */
    public function create(): View
    {
        return view('usuarios.create');
    }

    /**
     * Store a newly created internal user.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $fullName = trim($validated['nombres'].' '.$validated['apellidos']);

        User::query()->create([
            ...$validated,
            'name' => $fullName,
            'email' => Str::lower($validated['email']),
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
            // Contrasena temporal generada en servidor; en una fase futura se podra enviar o restablecer por correo.
            'password' => Str::password(16),
        ]);

        return Redirect::route('usuarios.index')
            ->with('status', 'Usuario creado correctamente.');
    }
}
