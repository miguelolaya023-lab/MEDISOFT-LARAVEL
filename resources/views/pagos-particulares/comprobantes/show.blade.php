<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Comprobante de pago particular</h2></x-slot>

    <article class="med-comprobante mx-auto my-8 max-w-3xl rounded-xl border border-violet-100 bg-white p-6 shadow-sm sm:p-10" aria-labelledby="titulo-comprobante">
        <header class="border-b border-violet-100 pb-5">
            <p class="text-sm font-semibold text-violet-800">MEDISOFT</p>
            <h3 id="titulo-comprobante" class="mt-2 text-2xl font-semibold text-slate-900">Comprobante de pago particular</h3>
            <p class="mt-2 break-all text-sm text-slate-700">Recibo {{ $pago->numero_recibo }}</p>
        </header>

        @if ($pago->estado === 'ANULADO')
            <p class="mt-5 rounded-md border border-red-600 p-3 font-semibold text-red-800">
                ANULADO <span class="font-normal">· Pago histórico sin vigencia</span>
            </p>
        @endif

        <dl class="grid gap-5 py-6 sm:grid-cols-2">
            <div><dt class="text-sm font-medium text-slate-600">Paciente</dt><dd>{{ $pago->nombres }} {{ $pago->apellidos }}</dd></div>
            <div><dt class="text-sm font-medium text-slate-600">Documento</dt><dd>{{ $pago->tipo_documento }} {{ $pago->numero_documento }}</dd></div>
            <div><dt class="text-sm font-medium text-slate-600">Fecha de pago</dt><dd>{{ $pago->fecha_pago->format('d/m/Y') }}</dd></div>
            <div><dt class="text-sm font-medium text-slate-600">Método de pago</dt><dd>{{ $pago->metodo_pago }}</dd></div>
            <div><dt class="text-sm font-medium text-slate-600">Estado</dt><dd>{{ $pago->estado }}</dd></div>
            <div><dt class="text-sm font-medium text-slate-600">Total pagado</dt><dd class="text-lg font-semibold">{{ $pago->valor_pagado }} COP</dd></div>
        </dl>

        <div class="med-comprobante-acciones flex flex-wrap items-center gap-4 border-t border-violet-100 pt-5" x-data>
            <x-primary-button type="button" @click="window.print()">Imprimir / Guardar como PDF</x-primary-button>
            <a href="{{ route('pagos-particulares.comprobantes.index') }}" class="font-medium text-violet-700 underline">Buscar otro recibo</a>
        </div>
    </article>
</x-app-layout>
