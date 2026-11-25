<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Liquidar consulta particular</h2></x-slot>

    <section class="mx-auto max-w-3xl space-y-4 px-4 py-8 sm:px-6" aria-labelledby="liquidacion-titulo">
        <div class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
            <h3 id="liquidacion-titulo" class="text-lg font-semibold text-gray-900">
                @if ($liquidacion)
                    Liquidación #{{ $liquidacion->id }}
                @else
                    Confirmar liquidación
                @endif
            </h3>

            <x-input-error :messages="$errors->all()" role="alert" />

            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-medium text-gray-600">Paciente</dt>
                    <dd>{{ $atencion->cita->paciente->nombres }} {{ $atencion->cita->paciente->apellidos }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Atención y cita</dt>
                    <dd>Atención #{{ $atencion->id }} · Cita #{{ $atencion->cita_id }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Fecha de la cita</dt>
                    <dd>{{ $atencion->cita->fecha->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Modalidad</dt>
                    <dd>PARTICULAR</dd>
                </div>
                @if ($liquidacion)
                    <div>
                        <dt class="font-medium text-gray-600">Fecha de liquidación</dt>
                        <dd>{{ $liquidacion->fecha_liquidacion->timezone('America/Bogota')->format('d/m/Y H:i:s') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-600">Estado</dt>
                        <dd class="font-semibold text-violet-800">{{ $liquidacion->estadoParticular() }}</dd>
                    </div>
                @else
                    <div>
                        <dt class="font-medium text-gray-600">Valor base</dt>
                        <dd>{{ $importe }} COP</dd>
                    </div>
                @endif
                <div>
                    <dt class="font-medium text-gray-600">{{ $liquidacion ? 'Total liquidado' : 'Total por liquidar' }}</dt>
                    <dd class="text-lg font-semibold text-violet-900">{{ $importe }} COP</dd>
                </div>
            </dl>

            <div class="flex flex-wrap items-center gap-4">
                @unless ($liquidacion)
                    <form method="POST" action="{{ route('pagos-particulares.liquidaciones.store', $atencion->id) }}">
                        @csrf
                        <input type="hidden" name="version_importe" value="{{ $version }}">
                        <x-primary-button name="confirmacion" value="1">Confirmar liquidación</x-primary-button>
                    </form>
                @endunless
                @if ($liquidacion && $liquidacion->estadoParticular() === 'PENDIENTE_PAGO')
                    @can('registrarPagoParticular')
                        <a href="{{ route('pagos-particulares.pagos.show', $liquidacion) }}"
                            class="rounded-sm font-medium text-violet-700 underline hover:text-violet-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-violet-700">Registrar pago</a>
                    @endcan
                @endif
                <a href="{{ route('pagos-particulares.liquidaciones.index') }}"
                    class="rounded-sm font-medium text-violet-700 underline hover:text-violet-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-violet-700">
                    {{ $liquidacion ? 'Volver a liquidaciones' : 'Cancelar' }}
                </a>
            </div>
        </div>
    </section>
</x-app-layout>
