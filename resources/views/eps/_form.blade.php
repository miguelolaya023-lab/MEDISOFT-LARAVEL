@foreach (['nit' => ['NIT', 50], 'nombre' => ['Nombre', 150], 'telefono' => ['Teléfono', 30], 'correo_electronico' => ['Correo electrónico', 255], 'direccion' => ['Dirección', 255], 'ciudad' => ['Ciudad', 100]] as $campo => [$etiqueta, $limite])
    <div>
        <x-input-label :for="$campo" :value="$etiqueta" />
        <x-text-input :id="$campo" :name="$campo" :type="$campo === 'correo_electronico' ? 'email' : 'text'" :value="is_string(old($campo, $eps->$campo ?? '')) ? old($campo, $eps->$campo ?? '') : ''" :maxlength="$limite" required class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get($campo)" class="mt-2" />
    </div>
@endforeach
<div>
    <x-input-label for="estado" value="Estado" />
    <select id="estado" name="estado" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-teal-600 focus:ring-teal-600">
        <option value="">Seleccione</option>
        @foreach (\App\Models\Eps::ESTADOS as $estado)
            <option value="{{ $estado }}" @selected(old('estado', $eps->estado ?? '') === $estado)>{{ $estado }}</option>
        @endforeach
    </select>
    <x-input-error :messages="$errors->get('estado')" class="mt-2" />
</div>
@if (isset($eps))
    <div>
        <x-input-label for="motivo" value="Motivo administrativo (obligatorio al cambiar el estado)" />
        <textarea id="motivo" name="motivo" rows="2" maxlength="400" class="mt-1 block w-full rounded-md border-gray-300 focus:border-teal-600 focus:ring-teal-600">{{ is_string(old('motivo')) ? old('motivo') : '' }}</textarea>
        <x-input-error :messages="$errors->get('motivo')" class="mt-2" />
    </div>
@endif
