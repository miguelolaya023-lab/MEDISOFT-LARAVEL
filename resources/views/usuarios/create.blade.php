<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            Crear usuario
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <form method="POST" action="{{ route('usuarios.store') }}" class="space-y-6">
                    @csrf

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <x-input-label for="tipo_documento" value="Tipo documento" />
                            <select id="tipo_documento" name="tipo_documento" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-teal-600 focus:ring-teal-600">
                                <option value="">Seleccione</option>
                                <option value="CC" @selected(old('tipo_documento') === 'CC')>Cedula de ciudadania</option>
                                <option value="CE" @selected(old('tipo_documento') === 'CE')>Cedula de extranjeria</option>
                                <option value="PAS" @selected(old('tipo_documento') === 'PAS')>Pasaporte</option>
                                <option value="TI" @selected(old('tipo_documento') === 'TI')>Tarjeta de identidad</option>
                            </select>
                            <x-input-error :messages="$errors->get('tipo_documento')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="numero_documento" value="Numero documento" />
                            <x-text-input id="numero_documento" name="numero_documento" type="text" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('numero_documento')" required />
                            <x-input-error :messages="$errors->get('numero_documento')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="nombres" value="Nombres" />
                            <x-text-input id="nombres" name="nombres" type="text" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('nombres')" required />
                            <x-input-error :messages="$errors->get('nombres')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="apellidos" value="Apellidos" />
                            <x-text-input id="apellidos" name="apellidos" type="text" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('apellidos')" required />
                            <x-input-error :messages="$errors->get('apellidos')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="email" value="Correo" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('email')" required />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="telefono" value="Telefono" />
                            <x-text-input id="telefono" name="telefono" type="text" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('telefono')" required />
                            <x-input-error :messages="$errors->get('telefono')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="cargo" value="Cargo" />
                            <x-text-input id="cargo" name="cargo" type="text" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('cargo')" required />
                            <x-input-error :messages="$errors->get('cargo')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="estado" value="Estado" />
                            <select id="estado" name="estado" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-teal-600 focus:ring-teal-600">
                                <option value="activo" @selected(old('estado', 'activo') === 'activo')>Activo</option>
                                <option value="inactivo" @selected(old('estado') === 'inactivo')>Inactivo</option>
                            </select>
                            <x-input-error :messages="$errors->get('estado')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('usuarios.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                            Cancelar
                        </a>

                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-teal-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-teal-800 focus:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                            Guardar usuario
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
