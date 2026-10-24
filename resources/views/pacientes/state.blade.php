<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-800">Estado del paciente</h2></x-slot>
    <div class="py-12">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            <div class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg">
                @if (session('status'))<p role="status" class="text-sm text-teal-800">{{ session('status') }}</p>@endif
                <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div><dt class="font-semibold text-gray-800">Paciente</dt><dd class="text-gray-600">{{ $paciente->nombres }} {{ $paciente->apellidos }}</dd></div>
                    <div><dt class="font-semibold text-gray-800">Código</dt><dd class="text-gray-600">{{ $paciente->id }}</dd></div>
                    <div><dt class="font-semibold text-gray-800">Estado actual</dt><dd class="text-gray-600">{{ $paciente->estado }}</dd></div>
                </dl>
                <p class="text-sm text-gray-600">El cambio conserva la ficha y el historial. Los motivos administrativos quedan registrados en auditoría.</p>
                <x-input-error :messages="$errors->all()" />
                @php($accion = $paciente->estado === 'ACTIVO' ? 'inactivate' : 'reactivate')
                @php($etiqueta = $paciente->estado === 'ACTIVO' ? 'Inactivar paciente' : 'Reactivar paciente')
                @if (in_array($paciente->estado, ['ACTIVO', 'INACTIVO'], true))
                    @can($accion, $paciente)
                        <form method="POST" action="{{ route('pacientes.'.$accion, $paciente) }}" class="space-y-4">
                            @csrf
                            @method('PATCH')
                            <div>
                                <x-input-label for="motivo" value="Motivo administrativo" />
                                <p id="motivo-ayuda" class="mt-1 text-sm text-gray-600">Escriba únicamente la justificación administrativa, sin diagnósticos, tratamientos ni otros datos clínicos.</p>
                                <textarea id="motivo" name="motivo" required maxlength="500" rows="3" aria-describedby="motivo-ayuda" class="mt-1 block w-full rounded-md border-gray-300 focus:border-teal-600 focus:ring-teal-600">{{ old('motivo') }}</textarea>
                                <x-input-error :messages="$errors->get('motivo')" class="mt-2" />
                            </div>
                            <label for="confirmacion" class="flex items-center gap-2 text-sm text-gray-800">
                                <input id="confirmacion" name="confirmacion" type="checkbox" value="1" required class="rounded border-gray-300 text-teal-700 focus:ring-teal-600" />
                                <span>Confirmo la operación: {{ $etiqueta }}.</span>
                            </label>
                            <x-input-error :messages="$errors->get('confirmacion')" />
                            <x-primary-button class="bg-teal-700 hover:bg-teal-800 focus:ring-teal-600">{{ $etiqueta }}</x-primary-button>
                        </form>
                    @endcan
                @endif
                @can('view', $paciente)
                    <a href="{{ route('pacientes.show', $paciente) }}" class="text-sm text-teal-700 underline">Cancelar / Volver a la ficha</a>
                @else
                    <a href="{{ route('dashboard') }}" class="text-sm text-teal-700 underline">Cancelar / Volver al inicio</a>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
