<x-app-layout>
<x-slot name="header"><h2 class="text-xl font-semibold">Consultar remisiones EPS</h2></x-slot>
<div class="py-12"><div class="mx-auto max-w-7xl px-4"><div class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
    <x-input-error :messages="$errors->all()" />
    <form method="GET" action="{{ route('remisiones.search') }}" class="flex flex-wrap items-end gap-4">
        <div><x-input-label for="buscar" value="Número de remisión o documento del paciente" /><x-text-input id="buscar" name="buscar" :value="$filtros['buscar'] ?? ''" maxlength="100" /></div>
        <div><x-input-label for="eps_id" value="EPS (opcional)" /><select id="eps_id" name="eps_id" class="rounded-md border-gray-300"><option value="">Todas</option>@foreach ($epsOptions as $eps)<option value="{{ $eps->id }}" @selected((string) ($filtros['eps_id'] ?? '') === (string) $eps->id)>{{ $eps->nombre }} — {{ $eps->nit }}</option>@endforeach</select></div>
        <x-primary-button>Buscar</x-primary-button><a href="{{ route('remisiones.search') }}" class="text-teal-700 underline">Limpiar</a>
    </form>
    @include('remisiones._table')
</div></div></div>
</x-app-layout>
