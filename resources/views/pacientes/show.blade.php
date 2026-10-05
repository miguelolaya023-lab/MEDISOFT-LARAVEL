<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-800">Ficha administrativa del paciente</h2></x-slot>
    <div class="py-12">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            <div class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg">
                @if (session('status'))<p role="status" class="text-sm text-teal-800">{{ session('status') }}</p>@endif
                <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach (['id' => 'Código', 'tipo_documento' => 'Tipo de documento', 'numero_documento' => 'Número de documento', 'nombres' => 'Nombres', 'apellidos' => 'Apellidos', 'sexo' => 'Sexo', 'telefono' => 'Teléfono', 'correo_electronico' => 'Correo electrónico', 'direccion' => 'Dirección', 'ciudad' => 'Ciudad', 'tipo_paciente' => 'Preferencia del paciente', 'estado' => 'Estado'] as $campo => $etiqueta)
                        <div><dt class="font-semibold text-gray-800">{{ $etiqueta }}</dt><dd class="text-gray-600">{{ $paciente->$campo ?? 'Sin registrar' }}</dd></div>
                    @endforeach
                    <div><dt class="font-semibold text-gray-800">Fecha de nacimiento</dt><dd class="text-gray-600">{{ $paciente->fecha_nacimiento->format('Y-m-d') }}</dd></div>
                </dl>
                <div class="flex flex-wrap gap-4">
                    @can('search', \App\Models\Paciente::class)<a href="{{ route('pacientes.search') }}" class="text-sm text-teal-700 underline">Consultar otro paciente</a>@endcan
                    @can('viewAny', \App\Models\Paciente::class)<a href="{{ route('pacientes.index') }}" class="text-sm text-teal-700 underline">Volver al listado</a>@endcan
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
