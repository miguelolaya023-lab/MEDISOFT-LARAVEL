<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Historial de pagos</h2></x-slot>

    <div class="mx-auto max-w-6xl space-y-5 px-4 py-8 sm:px-6">
        <form method="GET" action="{{ route('pacientes.historial-pagos.resultados', $pacienteId) }}" class="space-y-4 rounded-lg bg-white p-5 shadow-sm">
            @if ($paciente)
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ $paciente->nombres }} {{ $paciente->apellidos }}</h3>
                    <p class="text-sm text-gray-600">{{ $paciente->tipo_documento }} {{ $paciente->numero_documento }}</p>
                </div>
            @endif
            <x-input-error :messages="$errores ?? []" role="alert" />
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <x-input-label for="fecha_inicial" value="Fecha inicial" />
                    <x-text-input type="date" id="fecha_inicial" name="fecha_inicial" :value="$filtros['fecha_inicial'] ?? ''" class="mt-1 block w-full" required />
                </div>
                <div>
                    <x-input-label for="fecha_final" value="Fecha final" />
                    <x-text-input type="date" id="fecha_final" name="fecha_final" :value="$filtros['fecha_final'] ?? ''" class="mt-1 block w-full" required />
                </div>
                <div>
                    <x-input-label for="tipo_cobro" value="Tipo de cobro" />
                    <select id="tipo_cobro" name="tipo_cobro" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500">
                        <option value="">Todos</option>
                        <option value="PARTICULAR" @selected(($filtros['tipo_cobro'] ?? '') === 'PARTICULAR')>Pago particular</option>
                        <option value="CUOTA_MODERADORA" @selected(($filtros['tipo_cobro'] ?? '') === 'CUOTA_MODERADORA')>Cuota moderadora</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="estado" value="Estado" />
                    <select id="estado" name="estado" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500">
                        <option value="">Todos</option>
                        <option value="REGISTRADO" @selected(($filtros['estado'] ?? '') === 'REGISTRADO')>Registrado</option>
                        <option value="ANULADO" @selected(($filtros['estado'] ?? '') === 'ANULADO')>Anulado</option>
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-4">
                <x-primary-button>Consultar</x-primary-button>
                <a href="{{ route('pacientes.historial-pagos.formulario', $pacienteId) }}" class="font-medium text-violet-700 underline">Limpiar filtros</a>
                <a href="{{ route('pacientes.historial-pagos.buscar') }}" class="font-medium text-violet-700 underline">Buscar otro paciente</a>
            </div>
        </form>

        @if ($historial !== null)
            <section class="space-y-4 rounded-lg bg-white p-5 shadow-sm" aria-label="Resultados del historial de pagos">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-gray-700">Total vigente particular</dt>
                        <dd class="text-lg font-semibold text-violet-900">{{ $historial->totalParticular }} COP</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-700">Total vigente de cuota moderadora</dt>
                        <dd class="text-lg font-semibold text-violet-900">{{ $historial->totalCuotaModeradora }} COP</dd>
                    </div>
                </dl>
                <p class="text-sm text-gray-600">Los recaudos de cuota moderadora se incorporarán en el Sprint 4.</p>

                @if ($historial->movimientos->isEmpty())
                    <p class="text-sm text-gray-600">No se encontraron movimientos para los filtros seleccionados.</p>
                @else
                    @php($verComprobante = Gate::allows('emitirComprobanteParticular'))
                    <div class="relative overflow-x-auto" tabindex="0" role="region" aria-label="Tabla de movimientos">
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">Historial de pagos del paciente</caption>
                            <thead class="border-b border-violet-100 bg-violet-50 text-gray-800">
                                <tr>
                                    <th scope="col" class="px-3 py-2">Fecha</th>
                                    <th scope="col" class="px-3 py-2">Tipo de cobro</th>
                                    <th scope="col" class="px-3 py-2">Comprobante</th>
                                    <th scope="col" class="px-3 py-2">Valor</th>
                                    <th scope="col" class="px-3 py-2">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($historial->movimientos as $movimiento)
                                    <tr>
                                        <td class="whitespace-nowrap px-3 py-3">{{ $movimiento['fecha'] }}</td>
                                        <td class="px-3 py-3">Pago particular</td>
                                        <th scope="row" class="whitespace-nowrap px-3 py-3 font-medium">
                                            {{ $movimiento['comprobante'] }}
                                            @if ($verComprobante && $movimiento['tipo'] === 'PARTICULAR')
                                                <a href="{{ route('pagos-particulares.comprobantes.buscar', ['numero_recibo' => $movimiento['comprobante']]) }}" class="block font-medium text-violet-700 underline">
                                                    Ver comprobante<span class="sr-only"> {{ $movimiento['comprobante'] }}</span>
                                                </a>
                                            @endif
                                        </th>
                                        <td class="whitespace-nowrap px-3 py-3">{{ $movimiento['valor'] }} COP</td>
                                        <td class="px-3 py-3 font-semibold {{ $movimiento['estado'] === 'ANULADO' ? 'text-red-800' : 'text-emerald-800' }}">{{ $movimiento['estado'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                {{ $historial->movimientos->links() }}
            </section>
        @endif
    </div>
</x-app-layout>
