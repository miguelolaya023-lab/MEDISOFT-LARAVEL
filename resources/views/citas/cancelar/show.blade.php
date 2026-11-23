<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Cancelar consulta</h2></x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            <section class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
                @if (session('status'))
                    <p role="status" class="text-teal-700">{{ session('status') }}</p>
                @endif
                <x-input-error :messages="$errors->all()" />
                <h3 class="text-lg font-semibold">Cita #{{ $cita->id }} · {{ $cita->modalidad }} · {{ $cita->estado }}</h3>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="font-medium">Paciente</dt><dd>{{ $cita->paciente->nombres }} {{ $cita->paciente->apellidos }}</dd></div>
                    <div><dt class="font-medium">Médico</dt><dd>{{ $cita->medico->user->nombres }} {{ $cita->medico->user->apellidos }}</dd></div>
                    <div><dt class="font-medium">Horario de la cita (Colombia)</dt><dd>{{ $cita->horario() }}</dd></div>
                    @if ($cita->estado === 'CANCELADA' && $cita->motivo_cancelacion)
                        <div class="sm:col-span-2"><dt class="font-medium">Motivo de cancelación</dt><dd class="whitespace-pre-wrap">{{ $cita->motivo_cancelacion }}</dd></div>
                    @endif
                </dl>

                @if ($cita->estado === 'PROGRAMADA')
                    <form method="POST" action="{{ route('citas.cancelar.update', $cita) }}" class="space-y-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="version_horario" value="{{ $cita->versionHorario() }}" />
                        <div>
                            <x-input-label for="motivo_cancelacion" value="Motivo de cancelación" />
                            <textarea id="motivo_cancelacion" name="motivo_cancelacion" required maxlength="500" rows="3"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">{{ is_string(old('motivo_cancelacion')) ? old('motivo_cancelacion') : '' }}</textarea>
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="confirmacion" value="1" required class="rounded border-gray-300" />
                            Confirmo la cancelación y la liberación del horario.
                        </label>
                        <x-danger-button>Confirmar cancelación</x-danger-button>
                    </form>
                @elseif ($cita->estado !== 'CANCELADA')
                    <p>Solo se pueden cancelar citas en estado PROGRAMADA.</p>
                @endif
                <a href="{{ route('citas.cancelar.index') }}" class="inline-block text-teal-700 underline">Volver a buscar cita</a>
            </section>
        </div>
    </div>
</x-app-layout>
