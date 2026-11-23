<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Notificaciones de citas</h2></x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6">
            <section class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
                <p class="rounded-md bg-teal-50 p-3 text-sm text-teal-900">Simulación interna. No se envió ningún correo ni mensaje al paciente.</p>
                <form method="GET" action="{{ route('notificaciones.citas.index') }}" class="flex flex-wrap items-start gap-3">
                    <div>
                        <x-input-label for="cita_id" value="ID de cita" />
                        <x-text-input id="cita_id" name="cita_id" type="number" min="1" :value="$cita?->id" required class="mt-1 block" />
                        <x-input-error :messages="$errors->get('cita_id')" class="mt-2" />
                    </div>
                    <x-primary-button class="sm:mt-6">Consultar cita</x-primary-button>
                </form>

                @if ($cita)
                    <h3 class="font-semibold text-gray-900">Cita #{{ $cita->id }}</h3>
                    <p class="text-sm text-gray-700">{{ $cita->horario() }} · {{ $cita->modalidad }} · {{ str_replace('_', ' ', $cita->estado) }}</p>
                    <dl class="grid gap-3 rounded-md bg-gray-50 p-4 text-sm sm:grid-cols-2">
                        <div class="break-words"><dt class="font-semibold">Contacto registrado</dt><dd>{{ $vistaPrevia['contacto'] ?? 'Sin correo registrado' }}</dd></div>
                        <div><dt class="font-semibold">Asunto</dt><dd>{{ $vistaPrevia['asunto'] }}</dd></div>
                        <div class="sm:col-span-2"><dt class="font-semibold">Vista previa del mensaje</dt><dd>{{ $vistaPrevia['mensaje'] }}</dd></div>
                    </dl>
                    @if ($vistaPrevia['motivo_fallo'])
                        <p class="text-sm text-amber-900">{{ $vistaPrevia['motivo_fallo'] }} El intento quedará como NO_ENVIADA.</p>
                    @endif
                    <form method="POST" action="{{ route('notificaciones.citas.store', $cita) }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="vista_previa" value="{{ $vistaPrevia['token'] }}">
                        <x-input-error :messages="$errors->get('vista_previa')" />
                        <label class="block text-sm text-gray-800">
                            <input class="rounded border-gray-300 text-teal-700" type="checkbox" name="confirmacion" value="1" required>
                            Confirmo el contacto mostrado y el registro de este intento interno.
                        </label>
                        <x-input-error :messages="$errors->get('confirmacion')" />
                        <x-primary-button>Registrar simulación</x-primary-button>
                    </form>
                @endif
            </section>

            @if ($cita)
                <section class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="font-semibold text-gray-900">Historial de intentos de la cita #{{ $cita->id }}</h3>
                    <ul class="divide-y divide-gray-200">
                        @forelse ($historial as $intento)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                                <span>{{ $intento->fecha_intento->timezone('America/Bogota')->format('d/m/Y H:i:s') }} · {{ $intento->estado }}</span>
                                <a class="font-medium text-teal-700 underline" href="{{ route('notificaciones.citas.show', [$cita, $intento]) }}">Ver evidencia #{{ $intento->id }}</a>
                            </li>
                        @empty
                            <li class="text-sm text-gray-600">Esta cita aún no tiene intentos.</li>
                        @endforelse
                    </ul>
                    {{ $historial->links() }}
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
