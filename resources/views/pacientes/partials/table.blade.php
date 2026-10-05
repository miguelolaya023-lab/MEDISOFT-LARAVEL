<p class="px-6 py-4 text-sm font-medium text-gray-700">Total de registros: {{ $pacientes->count() }}</p>
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50"><tr>
            @foreach (['Código', 'Documento', 'Nombre', 'Ciudad', 'Preferencia', 'Estado', 'Ficha'] as $columna)<th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">{{ $columna }}</th>@endforeach
        </tr></thead>
        <tbody class="divide-y divide-gray-200 bg-white">
            @forelse ($pacientes as $paciente)
                <tr>
                    <td class="px-6 py-4 text-sm text-gray-700">{{ $paciente->id }}</td>
                    <td class="px-6 py-4 text-sm text-gray-700">{{ $paciente->tipo_documento }} {{ $paciente->numero_documento }}</td>
                    <td class="px-6 py-4 text-sm text-gray-700">{{ $paciente->nombres }} {{ $paciente->apellidos }}</td>
                    <td class="px-6 py-4 text-sm text-gray-700">{{ $paciente->ciudad }}</td>
                    <td class="px-6 py-4 text-sm text-gray-700">{{ $paciente->tipo_paciente }}</td>
                    <td class="px-6 py-4 text-sm text-gray-700">{{ $paciente->estado }}</td>
                    <td class="px-6 py-4 text-sm">@if ($puedeConsultar)<a href="{{ route('pacientes.show', $paciente) }}" class="text-teal-700 underline">Ver ficha</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-6 py-6 text-sm text-gray-600">No se encontraron pacientes.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
