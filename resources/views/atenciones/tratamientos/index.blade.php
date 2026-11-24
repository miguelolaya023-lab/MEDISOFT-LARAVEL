<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Tratamientos</h2></x-slot>

    <div class="mx-auto max-w-4xl space-y-4 px-4 py-8 sm:px-6">
        <section class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
            <h3 class="text-lg font-semibold">Atención #{{ $atencion->id }} · {{ $atencion->estado }}</h3>
            <p class="text-sm text-gray-700">
                {{ $atencion->cita->paciente->nombres }} {{ $atencion->cita->paciente->apellidos }}
                · Historia clínica #{{ $atencion->historia_clinica_id }}
            </p>
            @if (session('status'))
                <p role="status" class="text-teal-700">{{ session('status') }}</p>
            @endif
            <x-input-error :messages="$errors->all()" />

            @if ($editable)
                <form method="POST" action="{{ route('atenciones.clinica.tratamientos.store', $atencion) }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="clave_envio" value="{{ Str::uuid() }}">
                    <x-input-label for="nuevo-tratamiento" value="Descripción del tratamiento" />
                    <textarea id="nuevo-tratamiento" name="descripcion_tratamiento" required rows="4" class="block w-full rounded-md border-gray-300"></textarea>
                    <x-primary-button>Registrar tratamiento</x-primary-button>
                </form>
            @else
                <p class="text-sm text-gray-600">Los tratamientos de esta atención están disponibles en solo lectura.</p>
            @endif
        </section>

        <section class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
            <h3 class="text-lg font-semibold">Tratamientos registrados</h3>
            <ul class="divide-y divide-gray-200">
                @forelse ($tratamientos as $tratamiento)
                    <li class="space-y-2 py-4">
                        <p class="text-sm font-semibold">Tratamiento #{{ $tratamiento->id }} · {{ $tratamiento->fecha_registro->timezone('America/Bogota')->format('d/m/Y H:i') }}</p>
                        <p class="whitespace-pre-wrap break-words text-gray-800">{{ $tratamiento->descripcion }}</p>
                        @if ($editable)
                            <details>
                                <summary class="cursor-pointer text-teal-700 underline">Corregir tratamiento #{{ $tratamiento->id }}</summary>
                                <form method="POST" action="{{ route('atenciones.clinica.tratamientos.update', [$atencion, $tratamiento]) }}" class="mt-3 space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="version" value="{{ $tratamiento->versionClinica() }}">
                                    <x-input-label :for="'tratamiento-'.$tratamiento->id" value="Descripción corregida" />
                                    <textarea id="tratamiento-{{ $tratamiento->id }}" name="descripcion_tratamiento" required rows="4" class="block w-full rounded-md border-gray-300">{{ $tratamiento->descripcion }}</textarea>
                                    <x-primary-button>Guardar corrección</x-primary-button>
                                </form>
                            </details>
                        @endif
                    </li>
                @empty
                    <li class="py-4 text-gray-600">Esta atención todavía no tiene tratamientos registrados.</li>
                @endforelse
            </ul>
            {{ $tratamientos->links() }}
        </section>
        <a href="{{ route('atenciones.clinica.tratamientos.atenciones') }}" class="text-teal-700 underline">Volver a mis atenciones</a>
    </div>
</x-app-layout>
