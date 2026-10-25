<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-800">Listado de EPS</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg">
            <x-input-error :messages="$errors->all()" />
            <form method="GET" action="{{ route('eps.index') }}" class="flex flex-wrap items-end gap-4">
                <div><x-input-label for="ciudad" value="Ciudad" /><x-text-input id="ciudad" name="ciudad" :value="$filtros['ciudad'] ?? ''" maxlength="100" class="mt-1 block w-full" /></div>
                <div><x-input-label for="estado" value="Estado" /><select id="estado" name="estado" class="mt-1 rounded-md border-gray-300"><option value="">Todas</option>@foreach (\App\Models\Eps::ESTADOS as $estado)<option value="{{ $estado }}" @selected(($filtros['estado'] ?? '') === $estado)>{{ $estado }}</option>@endforeach</select></div>
                <x-primary-button class="bg-teal-700 hover:bg-teal-800">Filtrar</x-primary-button>
                <a href="{{ route('eps.index') }}" class="text-sm text-teal-700 underline">Limpiar filtros</a>
            </form>
            @include('eps._table')
        </div>
    </div></div>
</x-app-layout>
