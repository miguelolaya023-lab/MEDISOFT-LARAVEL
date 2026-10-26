<x-app-layout>
<x-slot name="header"><h2 class="text-xl font-semibold">Cambiar estado de remisión</h2></x-slot>
<div class="py-12"><div class="mx-auto max-w-4xl px-4"><div class="space-y-6 rounded-lg bg-white p-6 shadow-sm" x-data="{}">
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>Remisión {{ $remision->numero_remision }} — EPS {{ $remision->eps->nombre }} — Paciente {{ $remision->paciente->numero_documento }}. Estado actual: {{ $remision->estadoActual() }}.</p>
    <x-input-error :messages="$errors->all()" />
    @if (in_array($remision->estadoActual(), ['VIGENTE','VENCIDA'], true))
    <form method="POST" action="{{ route('remisiones.changeState', $remision) }}" class="space-y-6" x-on:submit="if (! window.confirm('¿Confirmar la anulación de la remisión?')) $event.preventDefault()">
        @csrf @method('PATCH')
        <input type="hidden" name="estado" value="ANULADA" />
        <x-input-label for="motivo" value="Motivo obligatorio" /><textarea id="motivo" name="motivo" required maxlength="500" class="block w-full rounded-md border-gray-300">{{ is_string(old('motivo')) ? old('motivo') : '' }}</textarea>
        <label class="flex items-center gap-2"><input type="checkbox" name="confirmacion" value="1" required /> Confirmo la anulación.</label>
        <x-primary-button>Anular remisión</x-primary-button>
    </form>
    @else<p>Este estado no permite transiciones manuales.</p>@endif
    <a href="{{ route('dashboard') }}" class="text-teal-700 underline">Volver</a>
</div></div></div>
</x-app-layout>
