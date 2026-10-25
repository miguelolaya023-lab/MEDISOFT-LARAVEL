<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-800">Historia clínica: antecedentes</h2></x-slot>
    <div class="py-12">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg" x-data="{}">
                @if (session('status'))<p role="status" class="text-sm text-teal-800">{{ session('status') }}</p>@endif
                <p class="text-gray-800">{{ $paciente->nombres }} {{ $paciente->apellidos }} · Historia {{ $historia->id }} · {{ $paciente->estado }}</p>
                @if ($puedeEscribir)
                    <h3 class="font-semibold text-gray-800">Registrar antecedente</h3>
                    <form method="POST" action="{{ route('antecedentes.store', $paciente) }}" class="space-y-4" x-on:submit="if (! window.confirm('¿Guardar el antecedente clínico?')) $event.preventDefault()">
                        @csrf
                        @include('antecedentes._form')
                    </form>
                @else
                    <p class="text-sm text-gray-600">Paciente inactivo: los antecedentes son de consulta. Debe reactivarse mediante el flujo autorizado antes de registrar o corregir.</p>
                @endif
                @can('view', $paciente)
                    <a href="{{ route('pacientes.show', $paciente) }}" class="text-sm text-teal-700 underline">Cancelar / Volver a la ficha administrativa</a>
                @else
                    <a href="{{ route('dashboard') }}" class="text-sm text-teal-700 underline">Cancelar / Volver al inicio</a>
                @endcan
            </div>
            <section class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg" aria-labelledby="antecedentes-registrados">
                <h3 id="antecedentes-registrados" class="font-semibold text-gray-800">Antecedentes registrados</h3>
                @forelse ($antecedentes as $registro)
                    <article class="space-y-2 border-b border-gray-100 pb-4">
                        <p class="text-sm text-gray-600">{{ $registro->fecha_registro->format('Y-m-d H:i') }} · {{ $registro->tipo ?? 'Sin tipo' }}</p>
                        <p class="whitespace-pre-wrap text-gray-800">{{ $registro->descripcion }}</p>
                        @if ($puedeEscribir)
                            <a href="{{ route('antecedentes.edit', ['paciente' => $paciente, 'antecedente' => $registro]) }}" class="text-sm text-teal-700 underline">Corregir antecedente</a>
                        @endif
                    </article>
                @empty
                    <p class="text-sm text-gray-600">No hay antecedentes registrados.</p>
                @endforelse
            </section>
        </div>
    </div>
</x-app-layout>
