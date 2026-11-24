<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Historia clínica</h1></x-slot>

    <div class="mx-auto grid max-w-5xl gap-5 px-4 py-8 sm:px-6">
        <section aria-labelledby="historia-paciente" class="grid gap-3 rounded-xl border border-violet-200 bg-violet-50 p-5 sm:p-6">
            <h2 id="historia-paciente" class="break-words text-2xl font-semibold text-violet-950">{{ $paciente->nombres }} {{ $paciente->apellidos }}</h2>
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="font-semibold text-gray-800">Documento</dt>
                    <dd class="break-words text-gray-700">{{ $paciente->tipo_documento }} {{ $paciente->numero_documento }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-800">Estado</dt>
                    <dd class="text-gray-700">{{ $paciente->estado }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-800">Apertura de historia #{{ $historia->id }}</dt>
                    <dd class="text-gray-700">{{ $historia->fecha_apertura->timezone(\App\Services\ConsultarHistoriaClinica::ZONA_CONSULTORIO)->format('d/m/Y') }}</dd>
                </div>
            </dl>
            @can('view', $paciente)
                <a href="{{ route('pacientes.show', $paciente) }}" class="w-fit text-sm font-medium text-violet-800 underline">Volver a la ficha del paciente</a>
            @endcan
        </section>

        <section aria-labelledby="historia-antecedentes" class="grid gap-4 rounded-xl border border-gray-200 bg-white p-5 sm:p-6">
            <h2 id="historia-antecedentes" class="text-lg font-semibold text-gray-900">Antecedentes clínicos</h2>
            <ul class="divide-y divide-gray-200">
                @forelse ($antecedentes as $antecedente)
                    <li class="grid gap-1 py-3 first:pt-0">
                        <h3 class="break-words font-medium text-gray-900">{{ $antecedente->tipo ?? 'Antecedente' }}</h3>
                        <p class="text-sm text-gray-600">{{ $antecedente->fecha_registro->timezone(\App\Services\ConsultarHistoriaClinica::ZONA_CONSULTORIO)->format('d/m/Y H:i') }} · {{ $antecedente->medico?->user?->name ?? 'Autor no disponible' }}</p>
                        <p class="whitespace-pre-wrap break-words text-gray-800">{{ $antecedente->descripcion }}</p>
                    </li>
                @empty
                    <li class="text-sm text-gray-600">No hay antecedentes clínicos para mostrar.</li>
                @endforelse
            </ul>
            {{ $antecedentes->links() }}
        </section>

        <section aria-labelledby="historia-atenciones" class="grid gap-4 rounded-xl border border-gray-200 bg-white p-5 sm:p-6">
            <div>
                <h2 id="historia-atenciones" class="text-lg font-semibold text-gray-900">Atenciones</h2>
                <p class="text-sm text-gray-600">En este alcance se muestran las atenciones del médico autenticado.</p>
            </div>
            <form method="GET" action="{{ route('pacientes.historia.show', $paciente) }}" class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_auto]">
                <div class="min-w-0">
                    <x-input-label for="historia-fecha-inicial" value="Fecha inicial (opcional)" />
                    <x-text-input id="historia-fecha-inicial" name="fechaInicial" type="date" :value="$filtros['fechaInicial'] ?? ''" class="mt-1 block w-full" />
                </div>
                <div class="min-w-0">
                    <x-input-label for="historia-fecha-final" value="Fecha final (opcional)" />
                    <x-text-input id="historia-fecha-final" name="fechaFinal" type="date" :value="$filtros['fechaFinal'] ?? ''" class="mt-1 block w-full" />
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <x-primary-button>Consultar atenciones</x-primary-button>
                    @if (!empty($filtros['fechaInicial']) || !empty($filtros['fechaFinal']))
                        <a href="{{ route('pacientes.historia.show', $paciente) }}" class="text-sm text-violet-800 underline">Limpiar fechas</a>
                    @endif
                </div>
            </form>

            <div class="grid gap-3">
                @forelse ($atenciones as $atencion)
                    @include('pacientes.historia.atencion')
                @empty
                    <p class="text-sm text-gray-600">No hay atenciones para mostrar.</p>
                @endforelse
            </div>
            {{ $atenciones->links() }}
        </section>
    </div>
</x-app-layout>
