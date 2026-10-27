<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Agendar consulta particular</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6">
        <section class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
            <x-input-error :messages="$errors->all()" />
            <p class="text-sm text-gray-600">Horarios de Colombia (America/Bogota). Consultar horarios no reserva el intervalo; se vuelve a comprobar al confirmar.</p>
            @if ($medicos->isEmpty())
                <p role="status">No hay médicos disponibles con cuenta activa y perfil profesional completo.</p>
            @else
                <form method="GET" action="{{ route('citas.create') }}" class="space-y-4">
                    <div><x-input-label for="medico_id" value="Médico" />
                        <select id="medico_id" name="medico_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">Seleccione un médico</option>
                            @foreach ($medicos as $medico)
                                <option value="{{ $medico->id }}" @selected(($seleccion['medico_id'] ?? '') == $medico->id)>{{ $medico->user->nombres }} {{ $medico->user->apellidos }} — {{ $medico->especialidad }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div><x-input-label for="fecha" value="Fecha" /><x-text-input id="fecha" name="fecha" type="date" :value="$seleccion['fecha'] ?? ''" required class="mt-1 block w-full" /></div>
                    <x-primary-button>Consultar horarios libres</x-primary-button>
                </form>
            @endif
        </section>
        @if (isset($seleccion['medico_id'], $seleccion['fecha']))
            <section class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold">Confirmar cita PARTICULAR para {{ $seleccion['fecha'] }}</h3>
                @if ($intervalos === [])
                    <p role="status">No hay intervalos libres para el médico y la fecha seleccionados.</p>
                @elseif ($pacientes->isEmpty())
                    <p role="status">No hay pacientes activos para agendar.</p>
                @else
                    <form method="POST" action="{{ route('citas.store') }}" class="space-y-6">
                        @csrf
                        <input type="hidden" name="medico_id" value="{{ $seleccion['medico_id'] }}" />
                        <input type="hidden" name="fecha" value="{{ $seleccion['fecha'] }}" />
                        <div><x-input-label for="paciente_id" value="Paciente activo" />
                            <select id="paciente_id" name="paciente_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                <option value="">Seleccione un paciente</option>
                                @foreach ($pacientes as $paciente)
                                    <option value="{{ $paciente->id }}" @selected(old('paciente_id') == $paciente->id)>{{ $paciente->tipo_documento }} {{ $paciente->numero_documento }} — {{ $paciente->nombres }} {{ $paciente->apellidos }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div><x-input-label for="hora_inicio" value="Intervalo completo" />
                            <select id="hora_inicio" name="hora_inicio" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                <option value="">Seleccione un horario</option>
                                @foreach ($intervalos as $intervalo)
                                    <option value="{{ $intervalo['inicio'] }}" @selected(old('hora_inicio') === $intervalo['inicio'])>{{ $intervalo['inicio'] }}–{{ $intervalo['fin'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div><x-input-label for="motivo_consulta" value="Motivo de la consulta" /><textarea id="motivo_consulta" name="motivo_consulta" maxlength="2000" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea><p class="text-sm text-gray-600">Ingrese solo el motivo necesario para agendar. Por privacidad, debe escribirlo nuevamente si ocurre un error de validación.</p></div>
                        <div><x-input-label for="valor_base" value="Valor base (COP)" /><x-text-input id="valor_base" name="valor_base" type="number" min="0.01" max="9999999999.99" step="0.01" :value="is_scalar(old('valor_base')) ? old('valor_base') : ''" required class="mt-1 block w-full" /></div>
                        <p class="text-sm text-gray-600">Esta operación no crea una atención ni registra un pago. La preferencia EPS del paciente no impide elegir consulta PARTICULAR.</p>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirmacion" value="1" required class="rounded border-gray-300 text-teal-700" /> Confirmo paciente, médico, fecha, intervalo y valor de la cita.</label>
                        <div class="flex gap-4"><x-primary-button>Agendar consulta particular</x-primary-button><a href="{{ route('citas.create') }}" class="text-teal-700 underline">Volver</a></div>
                    </form>
                @endif
            </section>
        @endif
    </div></div>
</x-app-layout>
