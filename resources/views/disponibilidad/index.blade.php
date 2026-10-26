<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Mi disponibilidad médica</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6">
        <section class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
            <h3 class="text-lg font-semibold">Configurar bloques</h3>
            @if (session('status'))<p role="status" class="text-teal-700">{{ session('status') }}</p>@endif
            <x-input-error :messages="$errors->all()" />
            <form method="POST" action="{{ route('disponibilidad.store') }}" class="space-y-6">
                @csrf
                @include('disponibilidad._fields')
                <div class="flex gap-4"><x-primary-button>Guardar disponibilidad</x-primary-button><a href="{{ route('dashboard') }}" class="text-teal-700 underline">Cancelar</a></div>
            </form>
        </section>
        <section class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <h3 class="p-6 text-lg font-semibold">Bloques configurados</h3>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50"><tr>@foreach (['Fecha', 'Inicio', 'Fin', 'Duración', 'Intervalos completos', 'Acción'] as $columna)<th scope="col" class="px-4 py-3 text-left text-sm font-semibold">{{ $columna }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($bloques as $bloque)
                        <tr>
                            <td class="px-4 py-3">{{ $bloque->fecha->toDateString() }}</td>
                            <td class="px-4 py-3">{{ substr($bloque->hora_inicio, 0, 5) }}</td>
                            <td class="px-4 py-3">{{ substr($bloque->hora_fin, 0, 5) }}</td>
                            <td class="px-4 py-3">{{ $bloque->duracion_minutos }} min</td>
                            <td class="px-4 py-3"><ul>@foreach ($bloque->intervalos() as $intervalo)<li>{{ $intervalo['inicio'] }}–{{ $intervalo['fin'] }}</li>@endforeach</ul></td>
                            <td class="px-4 py-3">@can('update', $bloque)<a href="{{ route('disponibilidad.edit', $bloque) }}" class="text-teal-700 underline">Editar bloque</a>@endcan</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-gray-600">No hay bloques de disponibilidad configurados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div></div>
</x-app-layout>
