<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Registrar pago particular</h2></x-slot>

    <section class="mx-auto max-w-3xl px-4 py-8 sm:px-6" aria-labelledby="pago-titulo">
        <div class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
            <h3 id="pago-titulo" class="text-lg font-semibold text-gray-900">Liquidación #{{ $liquidacion->id }}</h3>
            <x-input-error :messages="$errors->all()" role="alert" />
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-gray-600">Paciente</dt>
                    <dd>{{ $atencion->cita->paciente->nombres }} {{ $atencion->cita->paciente->apellidos }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-600">Total liquidado</dt>
                    <dd class="font-semibold text-violet-900">{{ $liquidacion->valor_total }} COP</dd>
                </div>
            </dl>
            <form method="POST" action="{{ route('pagos-particulares.pagos.store', $liquidacion) }}" class="space-y-5">
                @csrf
                <input type="hidden" name="clave_envio" value="{{ $clave }}">
                <input type="hidden" name="intencion" value="{{ $intencion }}">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="fecha_pago" value="Fecha de pago" />
                        <x-text-input class="mt-1 block w-full" type="date" id="fecha_pago" name="fecha_pago" :min="$desde" :max="$hoy" :value="$hoy" aria-describedby="pago-fechas" required />
                        <p id="pago-fechas" class="mt-1 text-sm text-gray-600">Desde {{ $desde }} hasta {{ $hoy }} (hora de Bogotá).</p>
                    </div>
                    <div>
                        <x-input-label for="valor_pagado" value="Valor pagado (COP)" />
                        <x-text-input class="mt-1 block w-full" id="valor_pagado" name="valor_pagado" inputmode="decimal" :value="$liquidacion->valor_total" required />
                    </div>
                </div>
                <div>
                    <x-input-label for="metodo_pago" value="Método de pago" />
                    <select id="metodo_pago" name="metodo_pago" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500">
                        <option value="">Seleccione un método</option>
                        @foreach ($metodos as $metodo)
                            <option value="{{ $metodo }}">{{ ucfirst(strtolower($metodo)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="observacion" value="Observación (opcional)" />
                    <textarea id="observacion" name="observacion" rows="3" maxlength="500" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500"></textarea>
                </div>
                <div class="flex flex-wrap items-center gap-4">
                    <x-primary-button name="confirmacion" value="1">Confirmar pago</x-primary-button>
                    <a href="{{ route('pagos-particulares.pagos.index') }}" class="rounded-sm font-medium text-violet-700 underline hover:text-violet-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-violet-700">Cancelar</a>
                </div>
            </form>
        </div>
    </section>
</x-app-layout>
