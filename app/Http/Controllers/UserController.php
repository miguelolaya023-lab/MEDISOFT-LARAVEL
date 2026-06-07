<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * Muestra el listado de usuarios internos.
     */
    public function index(Request $request): View
    {
        // El controlador coordina la busqueda enviada por la vista mediante el parametro GET "buscar".
        $criterio = trim((string) $request->query('buscar', ''));

        return view('usuarios.index', [
            'usuarios' => User::consultarUsuarioInterno($criterio)
                ->latest()
                ->paginate(10)
                ->withQueryString(),
            'buscar' => $criterio,
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
        // StoreUserRequest ejecuta la validacion antes de llegar aqui; el controlador solo coordina el flujo.
        $validated = $request->validated();

        // User representa conceptualmente a UsuarioInterno en el UML; este llamado corresponde
        // al mensaje UsuarioInterno.crearUsuarioInterno(datos) del diagrama de secuencia.
        User::crearUsuarioInterno($validated);

        return Redirect::route('usuarios.index')
            ->with('status', 'Usuario creado correctamente.');
    }

    /**
     * Actualiza un usuario interno existente.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->ensureInternalUser($user);

        // UpdateUserRequest ejecuta la validacion antes de llegar aqui; el controlador coordina el flujo.
        $validated = $request->validated();

        // User representa conceptualmente a UsuarioInterno en el UML; este llamado corresponde
        // al mensaje UsuarioInterno.actualizarUsuarioInterno(idUsuario, datos) del diagrama de secuencia.
        $user->actualizarUsuarioInterno($validated);

        return Redirect::route('usuarios.index')
            ->with('status', 'Usuario actualizado correctamente.');
    }

    /**
     * Inactiva un usuario interno sin eliminarlo del sistema.
     */
    public function inactivate(User $user): RedirectResponse
    {
        $this->ensureInternalUser($user);

        abort_if($user->getKey() === Auth::id(), 403);

        // User representa conceptualmente a UsuarioInterno en el UML; este llamado corresponde
        // al metodo inactivarUsuarioInterno() del diagrama y solo cambia el campo estado.
        $user->inactivarUsuarioInterno();

        return Redirect::route('usuarios.index')
            ->with('status', 'Usuario inactivado correctamente.');
    }

    /**
     * Activa un usuario interno previamente inactivo.
     */
    public function activate(User $user): RedirectResponse
    {
        $this->ensureInternalUser($user);

        // User representa conceptualmente a UsuarioInterno en el UML; este llamado corresponde
        // al metodo activarUsuarioInterno() del diagrama y solo cambia el campo estado.
        $user->activarUsuarioInterno();

        return Redirect::route('usuarios.index')
            ->with('status', 'Usuario activado correctamente.');
    }

    /**
     * Garantiza que solo usuarios internos se gestionen desde este modulo.
     */
    private function ensureInternalUser(User $user): void
    {
        abort_if($user->tipo_usuario !== User::TIPO_USUARIO_INTERNO, 404);
    }
}
