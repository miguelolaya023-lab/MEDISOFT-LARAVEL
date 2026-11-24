<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Fórmulas médicas</h2></x-slot>

    <div class="mx-auto grid max-w-4xl gap-4 px-4 py-8 sm:px-6">
        <section class="grid gap-4 rounded-lg bg-white p-6 shadow-sm">
            <div>
                <h3 class="text-lg font-semibold">Atención #{{ $atencion->id }} · {{ $atencion->estado }}</h3>
                <p class="text-sm text-gray-700">
                    {{ $atencion->cita->paciente->nombres }} {{ $atencion->cita->paciente->apellidos }}
                    · Historia clínica #{{ $atencion->historia_clinica_id }}
                </p>
            </div>

            @if (session('status'))
                <p role="status" class="text-teal-700">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <div role="alert">
                    <x-input-error :messages="$errors->all()" />
                </div>
            @endif

            @if ($editable)
                @include('atenciones.formulas.formulario')
            @else
                <p class="text-sm text-gray-600">Las fórmulas de esta atención están disponibles en solo lectura.</p>
            @endif
        </section>

        <section class="grid gap-4 rounded-lg bg-white p-6 shadow-sm">
            <h3 class="text-lg font-semibold">Fórmulas registradas</h3>
            @forelse ($formulas as $formula)
                <article class="grid gap-3 border-t border-gray-200 pt-4">
                    <h4 class="font-semibold">Fórmula #{{ $formula->id }} · {{ $formula->fecha_emision->timezone('America/Bogota')->format('d/m/Y H:i') }}</h4>
                    @if ($formula->observacion !== null)
                        <p class="whitespace-pre-wrap break-words text-sm text-gray-700">{{ $formula->observacion }}</p>
                    @endif
                    <ol class="grid gap-2">
                        @foreach ($formula->detalles as $detalle)
                            <li class="min-w-0 rounded-md bg-gray-50 p-3">
                                <p class="break-words font-medium">{{ $detalle->posicion }}. {{ $detalle->medicamento }}</p>
                                <dl class="mt-1 grid gap-2 break-words text-sm sm:grid-cols-2">
                                    <div><dt class="font-medium">Dosis</dt><dd>{{ $detalle->dosis }}</dd></div>
                                    @foreach (['frecuencia' => 'Frecuencia', 'duracion' => 'Duración', 'indicaciones' => 'Indicaciones'] as $campo => $etiqueta)
                                        @if ($detalle->$campo !== null)
                                            <div><dt class="font-medium">{{ $etiqueta }}</dt><dd class="whitespace-pre-wrap">{{ $detalle->$campo }}</dd></div>
                                        @endif
                                    @endforeach
                                </dl>
                            </li>
                        @endforeach
                    </ol>
                </article>
            @empty
                <p class="text-sm text-gray-600">Esta atención todavía no tiene fórmulas registradas.</p>
            @endforelse
            {{ $formulas->links() }}
        </section>

        <a href="{{ route('atenciones.clinica.formulas.atenciones') }}" class="text-teal-700 underline">Volver a mis atenciones</a>
    </div>
</x-app-layout>
