<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Atención médica</h2></x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            <section class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold">Mis citas y atenciones</h3>
                <ul class="divide-y divide-gray-200">
                    @forelse ($citas as $cita)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                            <div>
                                <p class="font-medium">Cita #{{ $cita->id }} · {{ $cita->paciente->nombres }} {{ $cita->paciente->apellidos }}</p>
                                <p class="text-sm text-gray-600">{{ $cita->horario() }} · {{ $cita->modalidad }} · {{ $cita->estado }}</p>
                            </div>
                            <a href="{{ $puedeConsultar && $cita->atencion ? route('atenciones.clinica.show', $cita->atencion) : route('atenciones.show', $cita) }}" class="text-teal-700 underline">
                                {{ $cita->atencion ? 'Ver atención' : 'Revisar cita' }}
                            </a>
                        </li>
                    @empty
                        <li class="py-4 text-gray-600">No hay citas ni atenciones para mostrar.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>
