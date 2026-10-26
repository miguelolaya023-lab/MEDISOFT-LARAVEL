<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Editar mi disponibilidad</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-4xl px-4 sm:px-6"><section class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
        <p>Bloque #{{ $disponibilidad->id }} de mi perfil Médico.</p>
        <x-input-error :messages="$errors->all()" />
        <form method="POST" action="{{ route('disponibilidad.update', $disponibilidad) }}" class="space-y-6">
            @csrf
            @method('PUT')
            @include('disponibilidad._fields')
            <div class="flex gap-4"><x-primary-button>Guardar cambios</x-primary-button><a href="{{ route('disponibilidad.index') }}" class="text-teal-700 underline">Cancelar</a></div>
        </form>
    </section></div></div>
</x-app-layout>
