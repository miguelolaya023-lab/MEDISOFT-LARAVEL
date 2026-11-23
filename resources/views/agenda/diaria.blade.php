<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Agenda diaria</h2></x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
            <section class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
                <form method="GET" action="{{ route('agenda.diaria.index') }}" class="flex flex-wrap items-start gap-4">
                    <div>
                        <x-input-label for="fecha" value="Fecha" />
                        <x-text-input id="fecha" name="fecha" type="date" :value="$fecha->toDateString()"
                            :min="\App\Http\Requests\ConsultarAgendaDiariaRequest::FECHA_MINIMA"
                            :max="\App\Http\Requests\ConsultarAgendaDiariaRequest::FECHA_MAXIMA" required class="mt-1 block" />
                        <x-input-error :messages="$errors->get('fecha')" class="mt-2" />
                    </div>
                    @if ($puedeSeleccionarMedico)
                        <div>
                            <x-input-label for="medico_id" value="Médico" />
                            <select id="medico_id" name="medico_id" required class="mt-1 block max-w-full rounded-md border-gray-300 shadow-sm">
                                <option value="">Seleccione un médico</option>
                                @foreach ($medicos as $opcion)
                                    <option value="{{ $opcion->id }}" @selected($medico?->id === $opcion->id)>
                                        {{ trim($opcion->user->nombres.' '.$opcion->user->apellidos) ?: $opcion->user->name }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('medico_id')" class="mt-2" />
                        </div>
                    @endif
                    <x-primary-button class="sm:mt-6">Consultar</x-primary-button>
                </form>

                @if ($medico)
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-900">{{ trim($medico->user->nombres.' '.$medico->user->apellidos) ?: $medico->user->name }}</h3>
                            <time datetime="{{ $fecha->toDateString() }}" class="text-sm text-gray-600">{{ $fecha->format('d/m/Y') }}</time>
                        </div>
                        <nav aria-label="Días de la agenda" class="flex gap-4 text-sm font-medium text-teal-700">
                            @if ($fecha->toDateString() > \App\Http\Requests\ConsultarAgendaDiariaRequest::FECHA_MINIMA)
                                <a class="underline" href="{{ route('agenda.diaria.index', ['fecha' => $fecha->subDay()->toDateString(), 'medico_id' => $medico->id]) }}">Día anterior</a>
                            @endif
                            @if ($fecha->toDateString() < \App\Http\Requests\ConsultarAgendaDiariaRequest::FECHA_MAXIMA)
                                <a class="underline" href="{{ route('agenda.diaria.index', ['fecha' => $fecha->addDay()->toDateString(), 'medico_id' => $medico->id]) }}">Día siguiente</a>
                            @endif
                        </nav>
                    </div>
                    @if ($citas->isEmpty())
                        <p class="rounded-md bg-gray-50 p-4 text-sm text-gray-700">No hay citas para el médico en esta fecha.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-gray-200 text-gray-700">
                                    <tr>
                                        <th scope="col" class="px-3 py-2">Cita</th>
                                        <th scope="col" class="px-3 py-2">Horario</th>
                                        <th scope="col" class="px-3 py-2">Paciente</th>
                                        <th scope="col" class="px-3 py-2">Modalidad</th>
                                        <th scope="col" class="px-3 py-2">Estado</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    @foreach ($citas as $cita)
                                        @php($clasesEstado = match ($cita->estado) {
                                            'PROGRAMADA' => 'bg-sky-100 text-sky-900',
                                            'EN_ATENCION' => 'bg-amber-100 text-amber-900',
                                            'ATENDIDA' => 'bg-emerald-100 text-emerald-900',
                                            'CANCELADA' => 'bg-rose-100 text-rose-900',
                                            default => 'bg-gray-100 text-gray-900',
                                        })
                                        <tr>
                                            <td class="px-3 py-3">#{{ $cita->id }}</td>
                                            <td class="whitespace-nowrap px-3 py-3">{{ $cita->horario() }}</td>
                                            <td class="px-3 py-3">{{ $cita->paciente->nombres }} {{ $cita->paciente->apellidos }}</td>
                                            <td class="px-3 py-3">{{ $cita->modalidad }}</td>
                                            <td class="px-3 py-3"><span class="inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold {{ $clasesEstado }}">{{ str_replace('_', ' ', $cita->estado) }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @else
                    <p class="text-sm text-gray-700">Seleccione un médico para consultar su agenda.</p>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
