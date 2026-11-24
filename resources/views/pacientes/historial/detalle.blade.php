<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Detalle de la consulta</h1></x-slot>

    <div class="mx-auto grid max-w-5xl gap-4 px-4 py-8 sm:px-6">
        <a href="{{ route('pacientes.historial.formulario', $paciente) }}" class="w-fit text-sm font-medium text-violet-800 underline">Volver al historial de consultas</a>
        <section class="grid gap-4 rounded-xl border border-gray-200 bg-white p-5 sm:p-6" aria-labelledby="detalle-paciente">
            <h2 id="detalle-paciente" class="break-words text-lg font-semibold text-gray-900">{{ $paciente->nombres }} {{ $paciente->apellidos }}</h2>
            @include('pacientes.historia.atencion')
        </section>
    </div>
</x-app-layout>
