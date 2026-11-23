<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Reprogramar consulta</h2></x-slot>

    <div class="py-12">
        <section class="mx-auto max-w-4xl space-y-4 rounded-lg bg-white p-6 shadow-sm">
            <x-input-error :messages="$errors->all()" />
            <form method="GET" action="{{ route('citas.reprogramar.index') }}" class="space-y-4">
                <div>
                    <x-input-label for="cita_id" value="ID de la cita" />
                    <x-text-input id="cita_id" name="cita_id" type="number" min="1" step="1" required class="mt-1 block w-full" />
                </div>
                <x-primary-button>Buscar cita</x-primary-button>
            </form>
        </section>
    </div>
</x-app-layout>
