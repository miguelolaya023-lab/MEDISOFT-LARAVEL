<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Usuarios
            </h2>

            <a href="{{ route('usuarios.create') }}" class="inline-flex items-center justify-center rounded-md border border-transparent bg-teal-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-teal-800 focus:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                Crear usuario interno
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-6 rounded-md border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-medium text-teal-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Nombre completo</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Tipo documento</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Numero documento</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Correo</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Telefono</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Cargo</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Estado</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-600">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @forelse ($usuarios as $usuario)
                                <tr>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                        {{ trim($usuario->nombres.' '.$usuario->apellidos) ?: $usuario->name }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                        {{ $usuario->tipo_documento ?? 'Sin registrar' }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                        {{ $usuario->numero_documento ?? 'Sin registrar' }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                        {{ $usuario->email }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                        {{ $usuario->telefono ?? 'Sin registrar' }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                        {{ $usuario->cargo ?? 'Sin registrar' }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm">
                                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $usuario->estado === 'activo' ? 'bg-teal-100 text-teal-800' : 'bg-gray-100 text-gray-700' }}">
                                            {{ ucfirst($usuario->estado) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="{{ route('usuarios.edit', $usuario) }}" class="inline-flex items-center rounded-md border border-teal-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-teal-700 transition duration-150 ease-in-out hover:bg-teal-50 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                                                Editar
                                            </a>

                                            {{-- La vista muestra Activar o Inactivar segun el estado actual del UsuarioInterno. --}}
                                            @if ($usuario->estado === 'activo' && $usuario->id !== auth()->id())
                                                <form method="POST" action="{{ route('usuarios.inactivate', $usuario) }}" onsubmit="return confirm('¿Está seguro de inactivar este usuario?')">
                                                    @csrf
                                                    @method('PATCH')

                                                    <button type="submit" class="inline-flex items-center rounded-md border border-red-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-red-700 transition duration-150 ease-in-out hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2">
                                                        Inactivar
                                                    </button>
                                                </form>
                                            @elseif ($usuario->estado === 'inactivo')
                                                <form method="POST" action="{{ route('usuarios.activate', $usuario) }}" onsubmit="return confirm('¿Está seguro de activar este usuario?')">
                                                    @csrf
                                                    @method('PATCH')

                                                    <button type="submit" class="inline-flex items-center rounded-md border border-teal-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-teal-700 transition duration-150 ease-in-out hover:bg-teal-50 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                                                        Activar
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-8 text-center text-sm text-gray-500">
                                        No hay usuarios registrados.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($usuarios->hasPages())
                    <div class="border-t border-gray-200 px-6 py-4">
                        {{ $usuarios->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
