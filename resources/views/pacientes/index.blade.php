<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-800">Pacientes</h2></x-slot>
    <div class="py-12">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <form method="GET" action="{{ route('pacientes.index') }}" class="grid grid-cols-1 items-end gap-4 border-b border-gray-200 p-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <x-input-label for="tipo_paciente" value="Preferencia del paciente" />
                        <select id="tipo_paciente" name="tipo_paciente" class="mt-1 block w-full rounded-md border-gray-300 focus:border-teal-600 focus:ring-teal-600">
                            <option value="">Todas</option>
                            @foreach (['PARTICULAR', 'EPS'] as $tipo)<option value="{{ $tipo }}" @selected(($filtros['tipo_paciente'] ?? '') === $tipo)>{{ $tipo }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="ciudad" value="Ciudad" />
                        <x-text-input id="ciudad" name="ciudad" :value="$filtros['ciudad'] ?? ''" maxlength="100" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="estado" value="Estado" />
                        <select id="estado" name="estado" class="mt-1 block w-full rounded-md border-gray-300 focus:border-teal-600 focus:ring-teal-600">
                            <option value="">Todos</option>
                            @foreach (['ACTIVO', 'INACTIVO'] as $estado)<option value="{{ $estado }}" @selected(($filtros['estado'] ?? '') === $estado)>{{ $estado }}</option>@endforeach
                        </select>
                    </div>
                    <div class="flex items-center gap-4">
                        <x-primary-button class="bg-teal-700 hover:bg-teal-800">Filtrar</x-primary-button>
                        <a href="{{ route('pacientes.index') }}" class="text-sm text-teal-700 underline">Limpiar</a>
                    </div>
                </form>
                <div class="px-6 pt-4"><x-input-error :messages="$errors->all()" /></div>
                @include('pacientes.partials.table')
            </div>
        </div>
    </div>
</x-app-layout>
