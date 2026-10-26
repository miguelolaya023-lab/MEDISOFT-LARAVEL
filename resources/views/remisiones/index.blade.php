<x-app-layout>
<x-slot name="header"><h2 class="text-xl font-semibold">Listado de remisiones EPS</h2></x-slot>
<div class="py-12"><div class="mx-auto max-w-7xl px-4"><div class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
    <x-input-error :messages="$errors->all()" />
    <form method="GET" action="{{ route('remisiones.index') }}" class="flex flex-wrap items-end gap-4">
        <div><x-input-label for="eps_id" value="EPS" /><select id="eps_id" name="eps_id" class="rounded-md border-gray-300"><option value="">Todas</option>@foreach ($epsOptions as $eps)<option value="{{ $eps->id }}" @selected((string) ($filtros['eps_id'] ?? '') === (string) $eps->id)>{{ $eps->nombre }} — {{ $eps->nit }}</option>@endforeach</select></div>
        <div><x-input-label for="estado" value="Estado" /><select id="estado" name="estado" class="rounded-md border-gray-300"><option value="">Todos</option>@foreach (\App\Models\RemisionEps::ESTADOS as $estado)<option value="{{ $estado }}" @selected(($filtros['estado'] ?? '') === $estado)>{{ $estado }}</option>@endforeach</select></div>
        <div><x-input-label for="vigencia_desde" value="Fecha de vencimiento desde (inclusive)" /><x-text-input id="vigencia_desde" name="vigencia_desde" type="date" :value="$filtros['vigencia_desde'] ?? ''" /></div>
        <div><x-input-label for="vigencia_hasta" value="Fecha de vencimiento hasta (inclusive)" /><x-text-input id="vigencia_hasta" name="vigencia_hasta" type="date" :value="$filtros['vigencia_hasta'] ?? ''" /></div>
        <x-primary-button>Filtrar</x-primary-button><a href="{{ route('remisiones.index') }}" class="text-teal-700 underline">Limpiar</a>
    </form>
    @include('remisiones._table')
</div></div></div>
</x-app-layout>
