<p class="px-6 py-4 text-sm font-medium">Total de registros: {{ $remisiones->count() }}</p>
<div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
    <thead class="bg-gray-50"><tr>@foreach (['Número', 'Paciente', 'EPS', 'Emisión', 'Vigencia hasta', 'Estado', 'Ficha'] as $columna)<th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase">{{ $columna }}</th>@endforeach</tr></thead>
    <tbody class="divide-y divide-gray-200">
    @forelse ($remisiones as $r)<tr>
        <td class="px-4 py-3">{{ $r->numero_remision }}</td><td class="px-4 py-3">{{ $r->paciente->tipo_documento }} {{ $r->paciente->numero_documento }} — {{ $r->paciente->nombres }} {{ $r->paciente->apellidos }}</td>
        <td class="px-4 py-3">{{ $r->eps->nombre }} ({{ $r->eps->nit }})</td><td class="px-4 py-3">{{ $r->fecha_remision->toDateString() }}</td><td class="px-4 py-3">{{ $r->vigencia_hasta->toDateString() }}</td><td class="px-4 py-3">{{ $r->estadoActual() }}</td>
        <td class="px-4 py-3">@if ($puedeConsultar)<a href="{{ route('remisiones.show', $r) }}" class="text-teal-700 underline">Ver ficha #{{ $r->id }}</a>@endif</td>
    </tr>@empty<tr><td colspan="7" class="px-4 py-6">No se encontraron remisiones.</td></tr>@endforelse
    </tbody>
</table></div>
