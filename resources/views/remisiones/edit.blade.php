<x-app-layout>
<x-slot name="header"><h2 class="text-xl font-semibold">Actualizar remisión EPS</h2></x-slot>
<div class="py-12"><div class="mx-auto max-w-4xl px-4"><div class="space-y-6 rounded-lg bg-white p-6 shadow-sm" x-data="{}">
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>Remisión {{ $remision->numero_remision }} — EPS {{ $remision->eps->nombre }} — Paciente {{ $remision->paciente->numero_documento }}. Estado: {{ $remision->estadoActual() }}.</p>
    <x-input-error :messages="$errors->all()" />
    <form method="POST" action="{{ route('remisiones.update', $remision) }}" class="space-y-6" x-on:submit="if (! window.confirm('¿Guardar la corrección?')) $event.preventDefault()">
        @csrf @method('PUT')
        @include('remisiones._fields')
        <x-primary-button>Guardar cambios</x-primary-button><a href="{{ route('dashboard') }}" class="text-teal-700 underline">Cancelar</a>
    </form>
</div></div></div>
</x-app-layout>
