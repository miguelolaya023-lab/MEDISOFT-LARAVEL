<p class="px-6 py-4 text-sm font-medium text-gray-700">Total de registros: {{ $entidades->count() }}</p>
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50"><tr>
            @foreach (['NIT', 'Nombre', 'Ciudad', 'Estado', 'Ficha'] as $columna)<th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">{{ $columna }}</th>@endforeach
        </tr></thead>
        <tbody class="divide-y divide-gray-200 bg-white">
            @forelse ($entidades as $entidad)
                <tr>
                    @foreach (['nit', 'nombre', 'ciudad', 'estado'] as $campo)<td class="px-6 py-4 text-sm text-gray-700">{{ $entidad->$campo }}</td>@endforeach
                    <td class="px-6 py-4 text-sm">@if ($puedeConsultar)<a href="{{ route('eps.show', $entidad) }}" class="text-teal-700 underline">Ver ficha</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-6 py-6 text-sm text-gray-600">No se encontraron EPS.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
