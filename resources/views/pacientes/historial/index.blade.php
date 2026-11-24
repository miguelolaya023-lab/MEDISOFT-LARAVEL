<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Historial de consultas</h1></x-slot>

    <div class="mx-auto grid max-w-5xl gap-5 px-4 py-8 sm:px-6">
        @unless (isset($errores))
            <section aria-labelledby="historial-paciente" class="grid gap-2 rounded-xl border border-violet-200 bg-violet-50 p-5 sm:p-6">
                <h2 id="historial-paciente" class="break-words text-2xl font-semibold text-violet-950">{{ $paciente->nombres }} {{ $paciente->apellidos }}</h2>
                <p class="text-sm text-gray-700">{{ $paciente->tipo_documento }} {{ $paciente->numero_documento }} · {{ $paciente->estado }}</p>
                @can('view', $paciente)
                    <a href="{{ route('pacientes.show', $paciente) }}" class="w-fit text-sm font-medium text-violet-800 underline">Volver a la ficha del paciente</a>
                @endcan
            </section>
        @endunless

        <section aria-labelledby="historial-filtros" class="grid gap-4 rounded-xl border border-gray-200 bg-white p-5 sm:p-6">
            <div class="grid gap-1">
                <h2 id="historial-filtros" class="text-lg font-semibold text-gray-900">Consultar por fechas</h2>
                <p class="text-sm text-gray-600">Selecciona ambas fechas. Se incluyen los dos días en hora de Bogotá.</p>
            </div>
            @isset($errores)
                <div role="alert"><x-input-error :messages="$errores" /></div>
            @endisset
            <form method="GET" action="{{ route('pacientes.historial.resultados', $paciente) }}" class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_auto]">
                <div class="min-w-0">
                    <x-input-label for="historial-fecha-inicial" value="Fecha inicial" />
                    <x-text-input id="historial-fecha-inicial" name="fechaInicial" type="date" :value="$filtros['fechaInicial'] ?? ''" required class="mt-1 block w-full" />
                </div>
                <div class="min-w-0">
                    <x-input-label for="historial-fecha-final" value="Fecha final" />
                    <x-text-input id="historial-fecha-final" name="fechaFinal" type="date" :value="$filtros['fechaFinal'] ?? ''" required class="mt-1 block w-full" />
                </div>
                <x-primary-button class="w-fit">Consultar</x-primary-button>
            </form>
        </section>

        @if ($atenciones !== null)
            <section aria-labelledby="historial-resultados" class="grid gap-4 rounded-xl border border-gray-200 bg-white p-5 sm:p-6">
                <h2 id="historial-resultados" class="text-lg font-semibold text-gray-900">Consultas del médico autenticado</h2>

                @can('viewAny', \App\Models\HistoriaClinica::class)
                    @php($puedeVerDetalle = true)
                @else
                    @php($puedeVerDetalle = false)
                @endcan

                <ul class="divide-y divide-gray-200">
                    @forelse ($atenciones as $atencion)
                        <li class="grid gap-3 py-4 first:pt-0 sm:grid-cols-[1fr_auto] sm:items-center">
                            <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <dt class="font-medium text-gray-700">Inicio</dt>
                                    <dd class="text-gray-900">{{ $atencion->fecha_hora_inicio->timezone(\App\Services\ConsultarAtencionesClinicas::ZONA_CONSULTORIO)->format('d/m/Y H:i') }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-gray-700">Modalidad</dt>
                                    <dd class="text-gray-900">{{ $atencion->cita->modalidad }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-gray-700">Estado</dt>
                                    <dd class="text-gray-900">{{ $atencion->estado }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-gray-700">Médico responsable</dt>
                                    <dd class="break-words text-gray-900">{{ $atencion->medico?->user?->name ?? 'No disponible' }}</dd>
                                </div>
                            </dl>
                            @if ($puedeVerDetalle)
                                <a href="{{ route('pacientes.historial.detalle', [$paciente, $atencion]) }}" aria-label="Ver atención {{ $atencion->id }}" class="w-fit text-sm font-medium text-violet-800 underline">Ver atención</a>
                            @endif
                        </li>
                    @empty
                        <li class="py-2 text-sm text-gray-600">No hay consultas en el rango seleccionado.</li>
                    @endforelse
                </ul>

                {{ $atenciones->links() }}
            </section>
        @endif
    </div>
</x-app-layout>
