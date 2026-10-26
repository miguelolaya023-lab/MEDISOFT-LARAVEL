<x-app-layout>
<x-slot name="header"><h2 class="text-xl font-semibold">Ficha de remisión EPS #{{ $remision->id }}</h2></x-slot>
<div class="py-12"><div class="mx-auto max-w-4xl px-4"><div class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    <dl class="grid gap-4 md:grid-cols-2">
        <div><dt class="font-semibold">Paciente</dt><dd>{{ $remision->paciente->nombres }} {{ $remision->paciente->apellidos }} — {{ $remision->paciente->tipo_documento }} {{ $remision->paciente->numero_documento }}</dd></div>
        <div><dt class="font-semibold">EPS</dt><dd>{{ $remision->eps->nombre }} — {{ $remision->eps->nit }}</dd></div>
        <div><dt class="font-semibold">Número</dt><dd>{{ $remision->numero_remision }}</dd></div>
        <div><dt class="font-semibold">Estado</dt><dd>{{ $remision->estadoActual() }}</dd></div>
        <div><dt class="font-semibold">Fecha de emisión</dt><dd>{{ $remision->fecha_remision->toDateString() }}</dd></div>
        <div><dt class="font-semibold">Vigencia hasta</dt><dd>{{ $remision->vigencia_hasta->toDateString() }}</dd></div>
        <div><dt class="font-semibold">Servicio autorizado</dt><dd class="whitespace-pre-wrap">{{ $remision->servicio_autorizado }}</dd></div>
        <div><dt class="font-semibold">Observación</dt><dd class="whitespace-pre-wrap">{{ $remision->observacion ?? 'Sin observación' }}</dd></div>
    </dl>
    <div class="flex flex-wrap gap-4">
        @can('update', $remision)<a href="{{ route('remisiones.edit', $remision) }}" class="text-teal-700 underline">Editar remisión</a>@endcan
        @can('changeState', $remision)@if (in_array($remision->estadoActual(), ['VIGENTE','VENCIDA'], true))<a href="{{ route('remisiones.state', $remision) }}" class="text-teal-700 underline">Anular remisión</a>@endif
        @endcan
        @can('search', \App\Models\RemisionEps::class)<a href="{{ route('remisiones.search') }}" class="text-teal-700 underline">Consultar remisiones</a>@endcan
    </div>
    <h3 class="font-semibold">Historial de cambios de estado</h3>
    @forelse ($remision->cambiosEstado as $cambio)<p>{{ $cambio->fecha_cambio->timezone('America/Bogota')->format('Y-m-d H:i:s') }} — {{ $cambio->estado_anterior }} → {{ $cambio->estado_nuevo }} — Responsable #{{ $cambio->user_id }}: {{ $cambio->motivo }}</p>@empty<p>Sin cambios manuales de estado.</p>@endforelse
</div></div></div>
</x-app-layout>
