<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Reprogramar consulta</h2></x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6">
            <section class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
                @if (session('status'))
                    <p role="status" class="text-teal-700">{{ session('status') }}</p>
                @endif
                <x-input-error :messages="$errors->all()" />
                <h3 class="text-lg font-semibold">Cita #{{ $cita->id }} · {{ $cita->modalidad }} · {{ $cita->estado }}</h3>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="font-medium">Paciente</dt><dd>{{ $cita->paciente->nombres }} {{ $cita->paciente->apellidos }}</dd></div>
                    <div><dt class="font-medium">Médico</dt><dd>{{ $cita->medico->user->nombres }} {{ $cita->medico->user->apellidos }}</dd></div>
                    <div><dt class="font-medium">Horario reservado (Colombia)</dt><dd>{{ $cita->horario() }}</dd></div>
                    @if ($cita->remision)
                        <div><dt class="font-medium">Remisión {{ $cita->remision->numero_remision }}</dt><dd>Vigente hasta {{ $cita->remision->vigencia_hasta->toDateString() }}</dd></div>
                    @endif
                </dl>
                @if ($cita->estado === 'PROGRAMADA')
                    <form method="GET" action="{{ route('citas.reprogramar.edit', $cita) }}" class="space-y-4">
                        <div>
                            <x-input-label for="fecha" value="Nueva fecha" />
                            <x-text-input id="fecha" name="fecha" type="date" :value="$fecha" :min="now('America/Bogota')->toDateString()" required class="mt-1 block w-full" />
                        </div>
                        <x-secondary-button type="submit">Consultar horarios libres</x-secondary-button>
                    </form>
                @endif
                @if ($errorDisponibilidad)
                    <x-input-error :messages="[$errorDisponibilidad]" />
                @elseif ($intervalos === [])
                    <p role="status">No hay intervalos libres para la fecha seleccionada.</p>
                @else
                    <form method="POST" action="{{ route('citas.reprogramar.update', $cita) }}" class="space-y-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="fecha" value="{{ $fecha }}" />
                        <input type="hidden" name="version_horario" value="{{ $cita->versionHorario() }}" />
                        <div>
                            <x-input-label for="hora_inicio" :value="'Intervalo para '.$fecha" />
                            <select id="hora_inicio" name="hora_inicio" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                <option value="">Seleccione un horario</option>
                                @foreach ($intervalos as $intervalo)
                                    <option value="{{ $intervalo['inicio'] }}" @selected(old('hora_inicio') === $intervalo['inicio'])>
                                        {{ $intervalo['inicio'] }}–{{ $intervalo['fin'] }}{{ $fecha === $cita->fecha->toDateString() && $intervalo['inicio'].':00' === $cita->hora_inicio ? ' (horario actual)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="confirmacion" value="1" required class="rounded border-gray-300 text-teal-700" />
                            Confirmo la fecha y el intervalo seleccionados.
                        </label>
                        <x-primary-button>Confirmar reprogramación</x-primary-button>
                    </form>
                @endif
                <a href="{{ route('citas.reprogramar.index') }}" class="inline-block text-teal-700 underline">Volver a buscar cita</a>
            </section>
        </div>
    </div>
</x-app-layout>
