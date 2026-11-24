<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Fórmulas médicas</h2></x-slot>

    <div class="mx-auto max-w-4xl space-y-4 px-4 py-8 sm:px-6">
        <section class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
            <h3 class="text-lg font-semibold">Mis atenciones</h3>
            <ul class="divide-y divide-gray-200">
                @forelse ($atenciones as $atencion)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                        <div>
                            <p class="font-medium">Atención #{{ $atencion->id }} · {{ $atencion->cita->paciente->nombres }} {{ $atencion->cita->paciente->apellidos }}</p>
                            <p class="text-sm text-gray-600">{{ $atencion->fecha_hora_inicio->timezone('America/Bogota')->format('d/m/Y H:i') }} · {{ $atencion->estado }}</p>
                        </div>
                        <a href="{{ route('atenciones.clinica.formulas.index', $atencion) }}" class="text-teal-700 underline">Ver fórmulas de la atención #{{ $atencion->id }}</a>
                    </li>
                @empty
                    <li class="py-4 text-gray-600">No hay atenciones para mostrar.</li>
                @endforelse
            </ul>
            {{ $atenciones->links() }}
        </section>
    </div>
</x-app-layout>
