<x-app-layout>
<x-slot name="header"><h2 class="text-xl font-semibold">Consultar remisiones EPS</h2></x-slot>
<div class="py-12"><div class="mx-auto max-w-7xl px-4"><div class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
    <x-input-error :messages="$errors->all()" />
    <form method="GET" action="{{ route('remisiones.search') }}" class="grid grid-cols-1 items-end gap-4 md:grid-cols-2">
        <div class="min-w-0"><x-input-label for="eps_id" value="EPS (obligatoria)" /><select id="eps_id" name="eps_id" required class="mt-1 block w-full rounded-md border-gray-300"><option value="">Seleccione una EPS</option>@foreach ($epsOptions as $eps)<option value="{{ $eps->id }}" @selected((string) ($filtros['eps_id'] ?? '') === (string) $eps->id)>{{ $eps->nombre }} — {{ $eps->nit }}</option>@endforeach</select></div>
        <div class="min-w-0"><x-input-label for="buscar" value="Número de remisión (opcional)" /><x-text-input id="buscar" name="buscar" :value="$filtros['buscar'] ?? ''" maxlength="100" class="mt-1 block w-full" /></div>
        <div class="flex items-center gap-4 md:col-span-2"><x-primary-button>Buscar</x-primary-button><a href="{{ route('remisiones.search') }}" class="text-teal-700 underline">Limpiar</a></div>
    </form>
    @if (empty($filtros['eps_id']))<p>Seleccione una EPS para consultar sus remisiones.</p>@endif
    @include('remisiones._table', ['totalRemisiones' => $remisiones instanceof \Illuminate\Pagination\LengthAwarePaginator ? $remisiones->total() : 0])
    @if ($remisiones instanceof \Illuminate\Pagination\LengthAwarePaginator){{ $remisiones->links() }}@endif
</div></div></div>
</x-app-layout>
