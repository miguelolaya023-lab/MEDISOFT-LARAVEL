<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Historia clínica</h1></x-slot>

    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
        <section class="grid gap-4 rounded-xl border border-gray-200 bg-white p-5 sm:p-6" aria-labelledby="historia-filtros">
            <h2 id="historia-filtros" class="font-semibold text-gray-900">Revisa los filtros de la consulta</h2>
            <div role="alert">
                <x-input-error :messages="$errores" />
            </div>
            <a href="{{ route('pacientes.historia.show', $paciente) }}" class="w-fit text-sm font-medium text-violet-800 underline">Volver a la historia clínica</a>
        </section>
    </div>
</x-app-layout>
