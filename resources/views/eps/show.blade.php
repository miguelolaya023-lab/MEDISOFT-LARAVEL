<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-800">Ficha de EPS</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
        <div class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg">
            @if (session('status'))<p role="status" class="text-sm text-teal-800">{{ session('status') }}</p>@endif
            <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach (['id' => 'Código', 'nit' => 'NIT', 'nombre' => 'Nombre', 'telefono' => 'Teléfono', 'correo_electronico' => 'Correo electrónico', 'direccion' => 'Dirección', 'ciudad' => 'Ciudad', 'estado' => 'Estado'] as $campo => $etiqueta)
                    <div><dt class="font-semibold text-gray-800">{{ $etiqueta }}</dt><dd class="text-gray-600">{{ $eps->$campo }}</dd></div>
                @endforeach
            </dl>
            <div class="flex flex-wrap gap-4">
                @can('update', $eps)<a href="{{ route('eps.edit', $eps) }}" class="text-sm text-teal-700 underline">Editar EPS</a>@endcan
                @can('search', \App\Models\Eps::class)<a href="{{ route('eps.search') }}" class="text-sm text-teal-700 underline">Consultar otra EPS</a>@endcan
                @can('viewAny', \App\Models\Eps::class)<a href="{{ route('eps.index') }}" class="text-sm text-teal-700 underline">Volver al listado</a>@endcan
            </div>
        </div>
    </div></div>
</x-app-layout>
