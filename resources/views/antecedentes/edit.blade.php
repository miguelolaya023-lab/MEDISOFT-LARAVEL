<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-800">Corregir antecedente clínico</h2></x-slot>
    <div class="py-12">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            <div class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg" x-data="{}">
                <p class="text-gray-800">{{ $paciente->nombres }} {{ $paciente->apellidos }}</p>
                <p class="text-sm text-gray-600">La corrección conserva el registro original y una versión clínica del texto anterior.</p>
                <form method="POST" action="{{ route('antecedentes.update', ['paciente' => $paciente, 'antecedente' => $antecedente]) }}" class="space-y-4" x-on:submit="if (! window.confirm('¿Guardar la corrección del antecedente clínico?')) $event.preventDefault()">
                    @csrf
                    @method('PUT')
                    @include('antecedentes._form')
                </form>
                <a href="{{ route('antecedentes.index', $paciente) }}" class="text-sm text-teal-700 underline">Cancelar / Volver a antecedentes</a>
            </div>
        </div>
    </div>
</x-app-layout>
