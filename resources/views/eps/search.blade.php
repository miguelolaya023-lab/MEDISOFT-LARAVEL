<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-800">Consultar EPS</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg">
            <x-input-error :messages="$errors->all()" />
            <form method="GET" action="{{ route('eps.search') }}" class="flex flex-wrap items-end gap-4">
                <div><x-input-label for="buscar" value="NIT o nombre" /><x-text-input id="buscar" name="buscar" :value="$buscar" maxlength="200" class="mt-1 block w-full" /></div>
                <x-primary-button class="bg-teal-700 hover:bg-teal-800">Buscar</x-primary-button>
            </form>
            @include('eps._table')
        </div>
    </div></div>
</x-app-layout>
