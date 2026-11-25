<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Emitir comprobante</h2></x-slot>

    <section class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <form method="GET" action="{{ route('pagos-particulares.comprobantes.buscar') }}" class="space-y-5 rounded-lg bg-white p-6 shadow-sm">
            @isset($error)
                <x-input-error :messages="[$error]" role="alert" />
            @endisset
            <div>
                <x-input-label for="numero_recibo" value="Número de recibo" />
                <x-text-input id="numero_recibo" name="numero_recibo" class="mt-1 block w-full" maxlength="29" autocomplete="off" spellcheck="false" required />
            </div>
            <x-primary-button>Buscar recibo</x-primary-button>
        </form>
    </section>
</x-app-layout>
