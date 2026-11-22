@props(['departamentos', 'municipios', 'paciente' => null])
@php
    $department = old('departamento_codigo', $paciente?->departamento_codigo) ?? '';
    $municipality = old('municipio_codigo', $paciente?->municipio_codigo) ?? '';
    $department = is_string($department) ? $department : '';
    $municipality = is_string($municipality) ? $municipality : '';
    $active = !$paciente || $paciente->departamento_codigo !== null || session()->hasOldInput('departamento_codigo') || session()->hasOldInput('municipio_codigo');
@endphp
<div class="space-y-4" x-data="{ activo: @js((bool) $active), departamento: @js($department), municipio: @js($municipality), catalogo: @js($municipios), get opciones() { return this.catalogo.filter(item => item.departamento_codigo === this.departamento); } }">
    <p class="text-sm text-gray-700">País: <strong>Colombia</strong></p>
    @if ($paciente)
        <p class="text-sm text-gray-600">Ciudad textual conservada: <strong>{{ $paciente->ciudad }}</strong>. Seleccionar una ubicación oficial no modifica este texto histórico.</p>
        @if (!$paciente->departamento_codigo)
            <p class="text-sm text-gray-600">La regularización geográfica es opcional. Puede guardar los demás datos sin seleccionar una ubicación.</p>
        @endif
    @endif
    @if ($paciente && !$paciente->departamento_codigo)
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" x-model="activo" /> Regularizar ubicación geográfica</label>
    @endif
    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <div>
            <x-input-label for="departamento_codigo" :value="$paciente && !$paciente->departamento_codigo ? 'Departamento (opcional para históricos)' : 'Departamento (obligatorio)'" />
            <select id="departamento_codigo" name="departamento_codigo" x-model="departamento" x-bind:disabled="!activo" x-on:change="municipio = ''" x-bind:required="activo" class="mt-1 block w-full rounded-md border-gray-300 focus:border-teal-600 focus:ring-teal-600">
                <option value="">Seleccione departamento</option>
                @foreach ($departamentos as $item)
                    <option value="{{ $item->codigo }}" @selected($department === $item->codigo)>{{ $item->nombre }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('departamento_codigo')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="municipio_codigo" value="Municipio o entidad territorial" />
            <select id="municipio_codigo" name="municipio_codigo" x-model="municipio" x-bind:disabled="!activo || !departamento" x-bind:required="activo" class="mt-1 block w-full rounded-md border-gray-300 focus:border-teal-600 focus:ring-teal-600">
                <option value="">Seleccione municipio</option>
                <template x-for="item in opciones" :key="item.codigo">
                    <option :value="item.codigo" :selected="item.codigo === municipio" x-text="item.nombre + (item.tipo_territorial === 'Municipio' ? '' : ' — ' + item.tipo_territorial)"></option>
                </template>
            </select>
            <x-input-error :messages="$errors->get('municipio_codigo')" class="mt-2" />
        </div>
    </div>
    @if ($departamentos->isEmpty())
        <p role="alert" class="text-sm text-red-700">El catálogo geográfico no está cargado. Solicite su preparación antes de registrar o regularizar ubicaciones.</p>
    @endif
    <noscript><p class="text-sm text-red-700">Active JavaScript para seleccionar departamento y municipio.</p></noscript>
</div>
