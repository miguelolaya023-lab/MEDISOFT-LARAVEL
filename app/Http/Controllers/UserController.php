<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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
     * Show the form for editing an internal user.
     */
    public function edit(User $user): View
    {
        $this->ensureInternalUser($user);

        return view('usuarios.edit', [
            'usuario' => $user,
        ]);
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

    /**
     * Update an existing internal user.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->ensureInternalUser($user);

        $validated = $request->validated();
        $fullName = trim($validated['nombres'].' '.$validated['apellidos']);

        $user->update([
            ...$validated,
            'name' => $fullName,
            'email' => Str::lower($validated['email']),
        ]);

        return Redirect::route('usuarios.index')
            ->with('status', 'Usuario actualizado correctamente.');
    }

    /**
     * Mark an internal user as inactive without deleting it.
     */
    public function inactivate(User $user): RedirectResponse
    {
        $this->ensureInternalUser($user);

        abort_if($user->getKey() === Auth::id(), 403);

        $user->update([
            'estado' => 'inactivo',
        ]);

        return Redirect::route('usuarios.index')
            ->with('status', 'Usuario inactivado correctamente.');
    }

    /**
     * Ensure only internal users can be managed from this module.
     */
    private function ensureInternalUser(User $user): void
    {
        abort_if($user->tipo_usuario !== User::TIPO_USUARIO_INTERNO, 404);
    }
}
