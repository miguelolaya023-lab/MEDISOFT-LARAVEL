<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Agendar consulta EPS</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6">
        <section class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
            <x-input-error :messages="$errors->all()" />
            <p class="text-sm text-gray-600">Horarios de Colombia (America/Bogota). La disponibilidad de la remisión y del horario se comprueba otra vez al confirmar.</p>
            @if ($medicos->isEmpty() || $pacientes->isEmpty())
                <p role="status">Se requiere un paciente activo y un médico activo con perfil profesional completo.</p>
            @else
                <form method="GET" action="{{ route('citas.eps.create') }}" class="space-y-4">
                    <div>
                        <x-input-label for="paciente_id" value="Paciente activo" />
                        <select id="paciente_id" name="paciente_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">Seleccione un paciente</option>
                            @foreach ($pacientes as $paciente)
                                <option value="{{ $paciente->id }}" @selected(($seleccion['paciente_id'] ?? '') == $paciente->id)>{{ $paciente->tipo_documento }} {{ $paciente->numero_documento }} — {{ $paciente->nombres }} {{ $paciente->apellidos }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="medico_id" value="Médico" />
                        <select id="medico_id" name="medico_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">Seleccione un médico</option>
                            @foreach ($medicos as $medico)
                                <option value="{{ $medico->id }}" @selected(($seleccion['medico_id'] ?? '') == $medico->id)>{{ $medico->user->nombres }} {{ $medico->user->apellidos }} — {{ $medico->especialidad }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="fecha" value="Fecha" />
                        <x-text-input id="fecha" name="fecha" type="date" :value="$seleccion['fecha'] ?? ''" required class="mt-1 block w-full" />
                    </div>
                    <x-primary-button>Consultar remisiones y horarios</x-primary-button>
                </form>
            @endif
        </section>
        @if (isset($seleccion['fecha']))
            <section class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold">Confirmar cita EPS para {{ $seleccion['fecha'] }}</h3>
                @if ($intervalos === [])
                    <p role="status">No hay intervalos libres para el médico y la fecha seleccionados.</p>
                @elseif ($remisiones->isEmpty())
                    <p role="status">El paciente no tiene remisiones disponibles con EPS activa para esta fecha.</p>
                @else
                    <form method="POST" action="{{ route('citas.eps.store') }}" class="space-y-4">
                        @csrf
                        @foreach (['paciente_id', 'medico_id', 'fecha'] as $campo)
                            <input type="hidden" name="{{ $campo }}" value="{{ $seleccion[$campo] }}" />
                        @endforeach
                        <div>
                            <x-input-label for="remision_id" value="Remisión" />
                            <select id="remision_id" name="remision_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                <option value="">Seleccione una remisión</option>
                                @foreach ($remisiones as $remision)
                                    <option value="{{ $remision->id }}" @selected(old('remision_id') == $remision->id)>{{ $remision->eps->nombre }} — {{ $remision->numero_remision }} (hasta {{ $remision->vigencia_hasta->toDateString() }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="hora_inicio" value="Intervalo completo" />
                            <select id="hora_inicio" name="hora_inicio" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                <option value="">Seleccione un horario</option>
                                @foreach ($intervalos as $intervalo)
                                    <option value="{{ $intervalo['inicio'] }}" @selected(old('hora_inicio') === $intervalo['inicio'])>{{ $intervalo['inicio'] }}–{{ $intervalo['fin'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="motivo_consulta" value="Motivo de la consulta" />
                            <textarea id="motivo_consulta" name="motivo_consulta" maxlength="2000" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                            <p class="text-sm text-gray-600">Por privacidad, escriba de nuevo el motivo si ocurre un error de validación.</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirmacion" value="1" required class="rounded border-gray-300 text-teal-700" /> Confirmo paciente, remisión, médico, fecha e intervalo de la cita.</label>
                        <div class="flex items-center gap-4">
                            <x-primary-button>Agendar consulta EPS</x-primary-button>
                            <a href="{{ route('citas.eps.create') }}">Volver</a>
                        </div>
                    </form>
                @endif
            </section>
        @endif
    </div></div>
</x-app-layout>
