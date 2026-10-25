<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            Editar usuario
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                @if (session('status'))<p role="status" class="mb-6 text-sm text-teal-800">{{ session('status') }}</p>@endif
                <x-input-error :messages="$errors->all()" class="mb-6" />
                <form method="POST" action="{{ route('usuarios.update', $usuario) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <x-input-label for="tipo_documento" value="Tipo documento" />
                            <select id="tipo_documento" name="tipo_documento" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-teal-600 focus:ring-teal-600">
                                <option value="">Seleccione</option>
                                <option value="CC" @selected(old('tipo_documento', $usuario->tipo_documento) === 'CC')>Cedula de ciudadania</option>
                                <option value="CE" @selected(old('tipo_documento', $usuario->tipo_documento) === 'CE')>Cedula de extranjeria</option>
                                <option value="PAS" @selected(old('tipo_documento', $usuario->tipo_documento) === 'PAS')>Pasaporte</option>
                                <option value="TI" @selected(old('tipo_documento', $usuario->tipo_documento) === 'TI')>Tarjeta de identidad</option>
                            </select>
                            <x-input-error :messages="$errors->get('tipo_documento')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="numero_documento" value="Numero documento" />
                            <x-text-input id="numero_documento" name="numero_documento" type="text" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('numero_documento', $usuario->numero_documento)" required />
                            <x-input-error :messages="$errors->get('numero_documento')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="nombres" value="Nombres" />
                            <x-text-input id="nombres" name="nombres" type="text" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('nombres', $usuario->nombres)" required />
                            <x-input-error :messages="$errors->get('nombres')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="apellidos" value="Apellidos" />
                            <x-text-input id="apellidos" name="apellidos" type="text" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('apellidos', $usuario->apellidos)" required />
                            <x-input-error :messages="$errors->get('apellidos')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="email" value="Correo" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('email', $usuario->email)" required />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="telefono" value="Telefono" />
                            <x-text-input id="telefono" name="telefono" type="text" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('telefono', $usuario->telefono)" required />
                            <x-input-error :messages="$errors->get('telefono')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="cargo" value="Cargo" />
                            <x-text-input id="cargo" name="cargo" type="text" class="mt-1 block w-full focus:border-teal-600 focus:ring-teal-600" :value="old('cargo', $usuario->cargo)" required />
                            <x-input-error :messages="$errors->get('cargo')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label value="Estado actual" />
                            <div class="mt-1 inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $usuario->estado === 'activo' ? 'bg-teal-100 text-teal-800' : 'bg-gray-100 text-gray-700' }}">
                                {{ ucfirst($usuario->estado) }}
                            </div>
                        </div>
                        @if ($usuario->medico)
                            @foreach (['registro_profesional' => 'Registro profesional', 'especialidad' => 'Especialidad'] as $campo => $label)
                                <div><x-input-label :for="$campo" :value="$label" /><x-text-input :id="$campo" :name="$campo" :value="old('registrar_perfil_medico') ? $usuario->medico->$campo : old($campo, $usuario->medico->$campo)" required class="mt-1 block w-full" /><x-input-error :messages="$errors->get($campo)" /></div>
                            @endforeach
                        @endif
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('usuarios.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                            Cancelar
                        </a>

                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-teal-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-teal-800 focus:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                            Guardar cambios
                        </button>
                    </div>
                </form>
                @unless ($usuario->medico)
                    <section class="mt-6 border-t border-gray-100 pt-6" aria-labelledby="registrar-perfil-medico">
                        <h3 id="registrar-perfil-medico" class="font-semibold text-gray-800">Registrar perfil profesional Médico</h3>
                        <p id="perfil-medico-ayuda" class="mt-2 text-sm text-gray-600">Registrar el perfil no cambia el rol, no concede permisos y no modifica los datos de la cuenta. El cambio de rol por sí solo no crea un perfil profesional.</p>
                        <form method="POST" action="{{ route('usuarios.medico.store', $usuario) }}" class="mt-4 space-y-4" x-data="{ registrarPerfil: {{ old('registrar_perfil_medico') === '1' ? 'true' : 'false' }} }">
                            @csrf
                            <label for="registrar_perfil_medico" class="flex items-center gap-2 text-sm text-gray-800">
                                <input id="registrar_perfil_medico" name="registrar_perfil_medico" type="checkbox" value="1" required x-model="registrarPerfil" aria-describedby="perfil-medico-ayuda" class="rounded border-gray-300 text-teal-700 focus:ring-teal-600" />
                                <span>Solicito registrar el perfil profesional Médico de este usuario.</span>
                            </label>
                            <x-input-error :messages="$errors->get('registrar_perfil_medico')" />
                            <fieldset class="grid grid-cols-1 gap-6 md:grid-cols-2" x-show="registrarPerfil" :disabled="! registrarPerfil">
                                <legend class="sr-only">Datos profesionales</legend>
                                @foreach (['registro_profesional' => 'Registro profesional', 'especialidad' => 'Especialidad'] as $campo => $etiqueta)
                                    <div>
                                        <x-input-label :for="$campo" :value="$etiqueta" />
                                        <x-text-input :id="$campo" :name="$campo" :value="is_string(old($campo)) ? old($campo) : ''" required maxlength="100" class="mt-1 block w-full" />
                                        <x-input-error :messages="$errors->get($campo)" class="mt-2" />
                                    </div>
                                @endforeach
                            </fieldset>
                            <x-primary-button class="bg-teal-700 hover:bg-teal-800 focus:ring-teal-600">Registrar perfil Médico</x-primary-button>
                        </form>
                    </section>
                @endunless
            </div>
        </div>
    </div>
</x-app-layout>
