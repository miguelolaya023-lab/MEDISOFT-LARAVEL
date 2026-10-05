<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-800">Registrar paciente</h2></x-slot>
    <div class="py-12">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <p role="status" class="mb-6 rounded-md border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-800">{{ session('status') }}</p>
            @endif
            <div class="bg-white p-6 shadow-sm sm:rounded-lg" x-data="{}">
                <form method="POST" action="{{ route('pacientes.store') }}" class="space-y-6" x-on:submit="if (! window.confirm('¿Confirmar el registro del paciente?')) $event.preventDefault()">
                    @csrf
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <x-input-label for="tipo_documento" value="Tipo de documento" />
                            <select id="tipo_documento" name="tipo_documento" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-teal-600 focus:ring-teal-600">
                                <option value="">Seleccione</option>
                                @foreach (\App\Models\Paciente::TIPOS_DOCUMENTO as $codigo => $etiqueta)
                                    <option value="{{ $codigo }}" @selected(old('tipo_documento') === $codigo)>{{ $codigo }} — {{ $etiqueta }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo_documento')" class="mt-2" />
                        </div>
                        @foreach (['numero_documento' => ['Número de documento', 30], 'nombres' => ['Nombres', 100], 'apellidos' => ['Apellidos', 100], 'telefono' => ['Teléfono', 30], 'direccion' => ['Dirección', 255], 'ciudad' => ['Ciudad', 100]] as $campo => [$etiqueta, $limite])
                            <div>
                                <x-input-label :for="$campo" :value="$etiqueta" />
                                <x-text-input :id="$campo" :name="$campo" :value="old($campo)" :maxlength="$limite" required class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" />
                                <x-input-error :messages="$errors->get($campo)" class="mt-2" />
                            </div>
                        @endforeach
                        <div>
                            <x-input-label for="sexo" value="Sexo" />
                            <select id="sexo" name="sexo" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-teal-600 focus:ring-teal-600">
                                <option value="">Seleccione</option>
                                @foreach (\App\Models\Paciente::SEXOS as $sexo)
                                    <option value="{{ $sexo }}" @selected(old('sexo') === $sexo)>{{ $sexo }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('sexo')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="fecha_nacimiento" value="Fecha de nacimiento" />
                            <x-text-input id="fecha_nacimiento" name="fecha_nacimiento" type="date" :value="old('fecha_nacimiento')" :max="now()->toDateString()" required class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('fecha_nacimiento')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="correo_electronico" value="Correo electrónico (opcional)" />
                            <x-text-input id="correo_electronico" name="correo_electronico" type="email" :value="old('correo_electronico')" maxlength="255" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('correo_electronico')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="tipo_paciente" value="Preferencia del paciente" />
                            <select id="tipo_paciente" name="tipo_paciente" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-teal-600 focus:ring-teal-600">
                                <option value="">Seleccione</option>
                                @foreach (['PARTICULAR', 'EPS'] as $tipo)<option value="{{ $tipo }}" @selected(old('tipo_paciente') === $tipo)>{{ $tipo }}</option>@endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo_paciente')" class="mt-2" />
                        </div>
                    </div>
                    <x-input-error :messages="$errors->all()" />
                    <div class="flex items-center gap-4">
                        <x-primary-button class="bg-teal-700 hover:bg-teal-800 focus:ring-teal-600">Guardar paciente</x-primary-button>
                        <a href="{{ route('dashboard') }}" class="text-sm text-teal-700 underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
