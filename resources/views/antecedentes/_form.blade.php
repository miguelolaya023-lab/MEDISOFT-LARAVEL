<div>
    <x-input-label for="tipo" value="Tipo (opcional)" />
    <x-text-input id="tipo" name="tipo" :value="old('tipo', $antecedente->tipo ?? '')" maxlength="100" class="mt-1 block w-full" />
    <x-input-error :messages="$errors->get('tipo')" class="mt-2" />
</div>
<div>
    <x-input-label for="descripcion" value="Descripción del antecedente clínico" />
    <textarea id="descripcion" name="descripcion" required maxlength="10000" rows="5" class="mt-1 block w-full rounded-md border-gray-300 focus:border-teal-600 focus:ring-teal-600">{{ old('descripcion', $antecedente->descripcion ?? '') }}</textarea>
    <x-input-error :messages="$errors->get('descripcion')" class="mt-2" />
</div>
<x-input-error :messages="$errors->all()" />
<x-primary-button class="bg-teal-700 hover:bg-teal-800 focus:ring-teal-600">Guardar antecedente</x-primary-button>
