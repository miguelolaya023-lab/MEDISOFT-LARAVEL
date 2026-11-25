<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Consultar pagos particulares</h2></x-slot>

    <div class="mx-auto max-w-6xl space-y-5 px-4 py-8 sm:px-6">
        <form method="GET" action="{{ route('pagos-particulares.consultas.index') }}" class="space-y-4 rounded-lg bg-white p-5 shadow-sm">
            <x-input-error :messages="$errores ?? []" role="alert" />
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div>
                    <x-input-label for="fecha_inicial" value="Fecha inicial" />
                    <x-text-input type="date" id="fecha_inicial" name="fecha_inicial" :value="$filtros['fecha_inicial'] ?? ''" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="fecha_final" value="Fecha final" />
                    <x-text-input type="date" id="fecha_final" name="fecha_final" :value="$filtros['fecha_final'] ?? ''" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="tipo_documento" value="Tipo de documento" />
                    <select id="tipo_documento" name="tipo_documento" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500">
                        <option value="">Sin especificar</option>
                        @foreach (\App\Models\Paciente::TIPOS_DOCUMENTO as $codigo => $tipo)
                            <option value="{{ $codigo }}" @selected(($filtros['tipo_documento'] ?? '') === $codigo)>{{ $tipo }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="documento" value="Documento del paciente" />
                    <x-text-input id="documento" name="documento" :value="$filtros['documento'] ?? ''" maxlength="30" autocomplete="off" class="mt-1 block w-full" />
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
            <p class="text-sm text-gray-600">Si filtras por fecha de pago, indica las dos fechas.</p>
            <div class="flex flex-wrap items-center gap-4">
                <x-primary-button>Consultar</x-primary-button>
                <a href="{{ route('pagos-particulares.consultas.index') }}" class="font-medium text-violet-700 underline">Limpiar filtros</a>
            </div>
        </form>

        @if ($pagos !== null)
            <section class="space-y-4 rounded-lg bg-white p-5 shadow-sm" aria-label="Resultados de pagos particulares">
                <p class="text-sm text-gray-700">Total vigente <strong class="ml-1 text-lg text-violet-900">{{ $total }} COP</strong></p>
                @if ($pagos->isEmpty())
                    <p class="text-sm text-gray-600">No se encontraron pagos en esta página para los filtros seleccionados.</p>
                @else
                    @php($verComprobante = Gate::allows('emitirComprobanteParticular'))
                    <div class="relative overflow-x-auto" tabindex="0" role="region" aria-label="Tabla de pagos particulares">
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">Pagos particulares</caption>
                            <thead class="border-b border-violet-100 bg-violet-50 text-gray-800">
                                <tr>
                                    <th scope="col" class="px-3 py-2">Recibo</th>
                                    <th scope="col" class="px-3 py-2">Paciente</th>
                                    <th scope="col" class="px-3 py-2">Fecha de pago</th>
                                    <th scope="col" class="px-3 py-2">Método</th>
                                    <th scope="col" class="px-3 py-2">Valor</th>
                                    <th scope="col" class="px-3 py-2">Estado</th>
                                    @if ($verComprobante)
                                        <th scope="col" class="px-3 py-2">Comprobante</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($pagos as $pago)
                                    <tr>
                                        <th scope="row" class="whitespace-nowrap px-3 py-3 font-medium">{{ $pago->numero_recibo }}</th>
                                        <td class="px-3 py-3">{{ $pago->nombres }} {{ $pago->apellidos }}</td>
                                        <td class="whitespace-nowrap px-3 py-3">{{ $pago->fecha_pago->format('d/m/Y') }}</td>
                                        <td class="px-3 py-3">{{ $pago->metodo_pago }}</td>
                                        <td class="whitespace-nowrap px-3 py-3">{{ $pago->valor_pagado }} COP</td>
                                        <td class="px-3 py-3 font-semibold {{ $pago->estado === 'ANULADO' ? 'text-red-800' : 'text-emerald-800' }}">{{ $pago->estado }}</td>
                                        @if ($verComprobante)
                                            <td class="px-3 py-3">
                                                <a href="{{ route('pagos-particulares.comprobantes.buscar', ['numero_recibo' => $pago->numero_recibo]) }}" class="font-medium text-violet-700 underline">
                                                    Ver comprobante<span class="sr-only"> {{ $pago->numero_recibo }}</span>
                                                </a>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                {{ $pagos->links() }}
            </section>
        @endif
    </div>
</x-app-layout>
