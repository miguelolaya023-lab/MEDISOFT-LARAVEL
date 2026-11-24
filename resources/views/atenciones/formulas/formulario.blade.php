<form method="POST" action="{{ route('atenciones.clinica.formulas.store', $atencion) }}"
    x-data="formularioFormulaMedica"
    data-maximo="{{ \App\Http\Requests\RegistrarFormulaMedicaRequest::MAXIMO_DETALLES }}"
    class="grid gap-4">
    @csrf
    <input type="hidden" name="clave_envio" value="{{ Str::uuid() }}">

    <div>
        <x-input-label for="observacion-formula" value="Observación (opcional)" />
        <textarea id="observacion-formula" name="observacion_formula" rows="2" class="block w-full rounded-md border-gray-300"></textarea>
    </div>

    <template x-for="(fila, indice) in filas" :key="fila">
        <fieldset class="min-w-0 rounded-lg border border-gray-200 p-4">
            <legend class="px-2 text-sm font-semibold" x-text="'Medicamento ' + (indice + 1)"></legend>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach (['medicamento' => 'Medicamento', 'dosis' => 'Dosis', 'frecuencia' => 'Frecuencia (opcional)', 'duracion' => 'Duración (opcional)'] as $campo => $etiqueta)
                    <label class="grid gap-1 text-sm font-medium">
                        {{ $etiqueta }}
                        <x-text-input type="text" class="w-full"
                            ::id="'formula-{{ $campo }}-' + fila"
                            ::name="'detalles_formula[' + indice + '][{{ $campo }}]'"
                            maxlength="255" :required="in_array($campo, ['medicamento', 'dosis'], true)" />
                    </label>
                @endforeach
                <label class="grid gap-1 text-sm font-medium sm:col-span-2">
                    Indicaciones (opcional)
                    <textarea :name="'detalles_formula[' + indice + '][indicaciones]'" rows="2" class="block w-full rounded-md border-gray-300"></textarea>
                </label>
            </div>
            <button type="button" @click="quitar(fila)" :disabled="filas.length <= 1"
                class="mt-3 rounded-md text-sm font-medium text-red-700 underline focus-visible:outline focus-visible:outline-2 disabled:opacity-50">
                Quitar medicamento <span x-text="indice + 1"></span>
            </button>
        </fieldset>
    </template>

    <div class="flex flex-wrap gap-3">
        <x-secondary-button id="formula-agregar" @click="agregar" x-bind:disabled="filas.length >= maximo">
            Añadir medicamento
        </x-secondary-button>
        <x-primary-button>Registrar fórmula médica</x-primary-button>
    </div>
</form>
