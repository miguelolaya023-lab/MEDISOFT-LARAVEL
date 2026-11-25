<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Registrar pago particular</h2></x-slot>

    <section class="mx-auto max-w-4xl px-4 py-8 sm:px-6" aria-labelledby="pagos-pendientes">
        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h3 id="pagos-pendientes" class="text-lg font-semibold text-gray-900">Liquidaciones pendientes</h3>
            <ul class="divide-y divide-gray-200">
                @forelse ($liquidaciones as $liquidacion)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                        <div>
                            <p class="font-medium">{{ $liquidacion->nombres }} {{ $liquidacion->apellidos }}</p>
                            <p class="text-sm text-gray-600">Liquidación #{{ $liquidacion->id }} · {{ $liquidacion->valor_total }} COP</p>
                        </div>
                        <a href="{{ route('pagos-particulares.pagos.show', $liquidacion) }}"
                            class="rounded-sm font-medium text-violet-700 underline hover:text-violet-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-violet-700">
                            Registrar pago<span class="sr-only"> de la liquidación #{{ $liquidacion->id }}</span>
                        </a>
                    </li>
                @empty
                    <li class="py-4 text-gray-600">No hay liquidaciones particulares pendientes.</li>
                @endforelse
            </ul>
            {{ $liquidaciones->links() }}
        </div>
    </section>
</x-app-layout>
