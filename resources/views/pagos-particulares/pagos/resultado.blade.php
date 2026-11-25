<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Resultado del pago particular</h2></x-slot>

    <section class="mx-auto max-w-3xl px-4 py-8 sm:px-6" aria-labelledby="pago-resultado">
        <div class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
            <h3 id="pago-resultado" class="text-lg font-semibold text-gray-900">Recibo {{ $pago->numero_recibo }}</h3>
            <x-input-error :messages="$errors->all()" role="alert" />
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-medium text-gray-600">Paciente</dt>
                    <dd>{{ $atencion->cita->paciente->nombres }} {{ $atencion->cita->paciente->apellidos }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Estado de la liquidación #{{ $liquidacion->id }}</dt>
                    <dd>{{ $liquidacion->estadoParticular() }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Fecha de pago</dt>
                    <dd>{{ $pago->fecha_pago->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Método de pago</dt>
                    <dd>{{ $pago->metodo_pago }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Valor pagado</dt>
                    <dd>{{ $pago->valor_pagado }} COP</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Estado del pago</dt>
                    <dd class="font-semibold text-violet-900">{{ $pago->estado }}</dd>
                </div>
            </dl>
            <div class="flex flex-wrap gap-4">
                @can('emitirComprobanteParticular')
                    <a href="{{ route('pagos-particulares.comprobantes.buscar', ['numero_recibo' => $pago->numero_recibo]) }}" class="rounded-sm font-medium text-violet-700 underline hover:text-violet-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-violet-700">Ver comprobante</a>
                @endcan
                <a href="{{ route('pagos-particulares.pagos.index') }}" class="rounded-sm font-medium text-violet-700 underline hover:text-violet-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-violet-700">Volver a liquidaciones pendientes</a>
            </div>
        </div>
    </section>
</x-app-layout>
