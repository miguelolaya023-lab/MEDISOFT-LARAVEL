<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Ficha de usuario</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-4xl px-4 sm:px-6">
        <div class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg" x-data="{}">
            @if (session('status'))<p role="status" class="text-teal-700">{{ session('status') }}</p>@endif
            <x-input-error :messages="$errors->all()" />
            <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach (['name' => 'Nombre', 'tipo_documento' => 'Tipo de documento', 'numero_documento' => 'Documento', 'email' => 'Correo', 'telefono' => 'Teléfono', 'cargo' => 'Cargo', 'estado' => 'Estado', 'motivo_inactivacion' => 'Último motivo de inactivación'] as $campo => $etiqueta)
                    <div><dt class="font-semibold">{{ $etiqueta }}</dt><dd>{{ $usuario->$campo ?? 'Sin registrar' }}</dd></div>
                @endforeach
                <div><dt class="font-semibold">Rol</dt><dd>{{ $usuario->rol?->nombre ?? 'Sin asignar' }}</dd></div>
                @if ($usuario->medico)
                    <div><dt class="font-semibold">Registro profesional Médico</dt><dd>{{ $usuario->medico->registro_profesional }}</dd></div>
                    <div><dt class="font-semibold">Especialidad</dt><dd>{{ $usuario->medico->especialidad }}</dd></div>
                @endif
            </dl>
            @can('update', $usuario)<a href="{{ route('usuarios.edit', $usuario) }}" class="text-teal-700 underline">Editar usuario</a>@endcan
            @can('changeState', $usuario)
                <form method="POST" action="{{ route($usuario->estado === 'activo' ? 'usuarios.inactivate' : 'usuarios.activate', $usuario) }}" class="space-y-3" x-on:submit="if (! window.confirm('¿Confirmar el cambio de estado?')) $event.preventDefault()">
                    @csrf @method('PATCH')
                    <x-input-label for="motivo" value="Motivo del cambio de estado" />
                    <x-text-input id="motivo" name="motivo" :value="old('motivo')" required maxlength="500" class="block w-full" />
                    <x-primary-button>{{ $usuario->estado === 'activo' ? 'Inactivar usuario' : 'Reactivar usuario' }}</x-primary-button>
                </form>
            @endcan
            @can('assignRole', $usuario)
                <form method="POST" action="{{ route('usuarios.assign-role', $usuario) }}" class="space-y-3" x-on:submit="if (! window.confirm('¿Confirmar la asignación de rol?')) $event.preventDefault()">
                    @csrf @method('PATCH')
                    <x-input-label for="rol_id" value="Asignar rol" />
                    <select id="rol_id" name="rol_id" required class="block w-full rounded-md border-gray-300">
                        @foreach ($roles as $rol)<option value="{{ $rol->id }}" @selected(old('rol_id', $usuario->rol_id) == $rol->id)>{{ $rol->nombre }}</option>@endforeach
                    </select>
                    <x-primary-button>Guardar rol</x-primary-button>
                </form>
            @endcan
            <a href="{{ route('usuarios.index') }}" class="text-teal-700 underline">Volver al listado</a>
        </div>
    </div></div>
</x-app-layout>
