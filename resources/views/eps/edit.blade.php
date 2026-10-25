<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-800">Editar EPS</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
        <div class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg" x-data="{}">
            @if (session('status'))<p role="status" class="text-sm text-teal-800">{{ session('status') }}</p>@endif
            <p class="text-sm text-gray-600">La edición conserva la identidad de la EPS y sus registros históricos.</p>
            <x-input-error :messages="$errors->all()" />
            <form method="POST" action="{{ route('eps.update', $eps) }}" class="space-y-6" x-on:submit="if (! window.confirm('¿Guardar los cambios de la EPS?')) $event.preventDefault()">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">@include('eps._form')</div>
                <x-primary-button class="bg-teal-700 hover:bg-teal-800 focus:ring-teal-600">Guardar cambios</x-primary-button>
                @can('view', $eps)<a href="{{ route('eps.show', $eps) }}" class="text-sm text-teal-700 underline">Cancelar</a>@else<a href="{{ route('dashboard') }}" class="text-sm text-teal-700 underline">Cancelar</a>@endcan
            </form>
        </div>
    </div></div>
</x-app-layout>
