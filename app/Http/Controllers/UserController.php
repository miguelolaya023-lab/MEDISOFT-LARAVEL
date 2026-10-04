<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignUserRoleRequest;
use App\Http\Requests\ChangeUserStateRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Auditoria;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $criterio = trim((string) $request->query('buscar', ''));

        return view('usuarios.index', ['usuarios' => User::consultarUsuarioInterno($criterio)->with('rol')->latest()->paginate(10)->withQueryString(), 'buscar' => $criterio]);
    }

    public function create(): View
    {
        return view('usuarios.create', ['roles' => Rol::query()->orderBy('nombre')->get()]);
    }

    public function show(User $user): View
    {
        return view('usuarios.show', ['usuario' => $user->load('rol', 'medico'), 'roles' => Rol::query()->orderBy('nombre')->get()]);
    }

    public function edit(User $user): View
    {
        return view('usuarios.edit', ['usuario' => $user]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $usuario = DB::transaction(function () use ($request): User {
            $datos = $request->validated();
            $usuario = User::crearUsuarioInterno(Arr::except($datos, ['es_medico', 'registro_profesional', 'especialidad']));
            if ($request->boolean('es_medico')) {
                $usuario->medico()->create(Arr::only($datos, ['registro_profesional', 'especialidad']));
            }
            Auditoria::registrar('USUARIO_CREADO', $usuario, 'Cuenta interna creada; rol '.$usuario->rol_id.'.');
            Auditoria::registrar('ROL_ASIGNADO', $usuario, 'Rol inicial '.$usuario->rol_id.'.');

            return $usuario;
        });

        return redirect()->route('usuarios.show', $usuario)->with('status', 'Usuario creado correctamente. La contraseña puede establecerse mediante recuperación.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $datos = $request->validated();
            $user->actualizarUsuarioInterno(Arr::except($datos, ['registro_profesional', 'especialidad']));
            $perfil = Arr::only($datos, ['registro_profesional', 'especialidad']);
            if ($perfil !== []) {
                $user->medico()->update($perfil);
            }
            Auditoria::registrar('USUARIO_ACTUALIZADO', $user, 'Datos de identificación y contacto actualizados.');
        });

        return redirect()->route('usuarios.show', $user)->with('status', 'Usuario actualizado correctamente.');
    }

    public function inactivate(ChangeUserStateRequest $request, User $user): RedirectResponse
    {
        return $this->changeState($request, $user, 'inactivo');
    }

    public function activate(ChangeUserStateRequest $request, User $user): RedirectResponse
    {
        return $this->changeState($request, $user, 'activo');
    }

    private function changeState(ChangeUserStateRequest $request, User $user, string $estado): RedirectResponse
    {
        DB::transaction(function () use ($request, $user, $estado): void {
            $this->lockAdministration();
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->estado === $estado) {
                throw ValidationException::withMessages(['motivo' => 'La cuenta ya tiene ese estado.']);
            }
            if ($estado === 'inactivo') {
                $this->protectLastAdministrator($user);
                abort_if($user->id === $request->user()->id, 403);
            }
            $motivo = $request->validated('motivo');
            $user->update(['estado' => $estado, ...($estado === 'inactivo' ? ['motivo_inactivacion' => $motivo] : [])]);
            Auditoria::registrar($estado === 'activo' ? 'USUARIO_REACTIVADO' : 'USUARIO_INACTIVADO', $user, $motivo);
        });

        return redirect()->route('usuarios.show', $user)->with('status', $estado === 'activo' ? 'Usuario activado correctamente.' : 'Usuario inactivado correctamente.');
    }

    public function assignRole(AssignUserRoleRequest $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $this->lockAdministration();
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $anterior = $user->rol_id;
            $nuevo = (int) $request->validated('rol_id');
            if ($anterior === $nuevo) {
                return;
            }
            $wasAdministrator = $user->puedeAdministrarUsuarios();
            $user->rol_id = $nuevo;
            if ($wasAdministrator && ! $user->puedeAdministrarUsuarios()) {
                $user->rol_id = $anterior;
                $this->protectLastAdministrator($user);
                $user->rol_id = $nuevo;
            }
            $user->save();
            Auditoria::registrar('ROL_CAMBIADO', $user, 'Rol '.$anterior.' cambiado a '.$nuevo.'.');
        });

        return redirect()->route('usuarios.show', $user)->with('status', 'Rol asignado correctamente.');
    }

    private function lockAdministration(): void
    {
        Rol::query()->orderBy('id')->lockForUpdate()->get();
    }

    private function protectLastAdministrator(User $user): void
    {
        if ($user->puedeAdministrarUsuarios() && ! User::query()->where('estado', 'activo')->where('id', '!=', $user->id)->lockForUpdate()->get()->contains(fn (User $candidate): bool => $candidate->puedeAdministrarUsuarios())) {
            throw ValidationException::withMessages(['rol_id' => 'Debe conservarse al menos una cuenta administrativa activa.', 'motivo' => 'Debe conservarse al menos una cuenta administrativa activa.']);
        }
    }
}
