<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-800">Consultar paciente</h2></x-slot>
    <div class="py-12">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <form method="GET" action="{{ route('pacientes.search') }}" class="flex flex-col gap-4 border-b border-gray-200 p-6 sm:flex-row sm:items-end">
                    <div class="grow">
                        <x-input-label for="buscar" value="Documento o nombre" />
                        <x-text-input id="buscar" name="buscar" type="search" :value="$buscar" maxlength="200" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" />
                        <x-input-error :messages="$errors->get('buscar')" class="mt-2" />
                    </div>
                    <div class="flex items-center gap-4">
                        <x-primary-button class="bg-teal-700 hover:bg-teal-800">Buscar</x-primary-button>
                        <a href="{{ route('pacientes.search') }}" class="text-sm text-teal-700 underline">Limpiar</a>
                    </div>
                </form>
                @if ($buscar !== '')
                    @include('pacientes.partials.table')
                @else
                    <p class="p-6 text-sm text-gray-600">Ingrese un documento o nombre para consultar pacientes.</p>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
