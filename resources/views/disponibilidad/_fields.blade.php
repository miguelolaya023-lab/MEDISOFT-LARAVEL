@if (isset($disponibilidad))
    <div>
        <x-input-label for="fecha" value="Fecha" />
        <x-text-input id="fecha" name="fecha" type="date" :value="is_string(old('fecha', $disponibilidad->fecha->toDateString())) ? old('fecha', $disponibilidad->fecha->toDateString()) : ''" required class="mt-1 block w-full" />
    </div>
@else
    @php
        $fechasAnteriores = old('fechas');
        $fechasIniciales = is_array($fechasAnteriores) && $fechasAnteriores !== []
            ? array_map(fn ($fecha) => is_string($fecha) ? $fecha : '', array_values($fechasAnteriores))
            : [''];
    @endphp
    <fieldset class="space-y-3" x-data="{
        fechas: {{ Illuminate\Support\Js::from($fechasIniciales) }}.map((valor, id) => ({ id, valor })),
        siguiente: {{ count($fechasIniciales) }},
        quitarFecha(id) {
            this.fechas = this.fechas.filter(fila => fila.id !== id);
            if (this.fechas.length === 0) {
                this.fechas.push({ id: this.siguiente++, valor: '' });
            }
        }
    }">
        <legend class="font-medium text-gray-900">Fechas de trabajo</legend>
        <template x-for="(fila, indice) in fechas" :key="fila.id">
            <div>
                <label :for="'fecha-' + fila.id" x-text="'Fecha ' + (indice + 1)" class="text-sm text-gray-700"></label>
                <input :id="'fecha-' + fila.id" type="date" name="fechas[]" x-model="fila.valor" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" />
                <button type="button" x-on:click="quitarFecha(fila.id)" class="text-sm text-teal-700 underline">Quitar fecha</button>
            </div>
        </template>
        <button type="button" x-on:click="fechas.push({ id: siguiente++, valor: '' })" class="text-sm font-medium text-teal-700 underline">Añadir fecha</button>
    </fieldset>
@endif
<div class="grid gap-4 sm:grid-cols-3">
    <div><x-input-label for="hora_inicio" value="Hora inicial" /><x-text-input id="hora_inicio" name="hora_inicio" type="time" :value="is_string(old('hora_inicio', isset($disponibilidad) ? substr($disponibilidad->hora_inicio, 0, 5) : '')) ? old('hora_inicio', isset($disponibilidad) ? substr($disponibilidad->hora_inicio, 0, 5) : '') : ''" required class="mt-1 block w-full" /></div>
    <div><x-input-label for="hora_fin" value="Hora final" /><x-text-input id="hora_fin" name="hora_fin" type="time" :value="is_string(old('hora_fin', isset($disponibilidad) ? substr($disponibilidad->hora_fin, 0, 5) : '')) ? old('hora_fin', isset($disponibilidad) ? substr($disponibilidad->hora_fin, 0, 5) : '') : ''" required class="mt-1 block w-full" /></div>
    <div><x-input-label for="duracion_minutos" value="Duración por intervalo (minutos)" /><x-text-input id="duracion_minutos" name="duracion_minutos" type="number" min="1" step="1" :value="is_scalar(old('duracion_minutos', $disponibilidad->duracion_minutos ?? '')) ? old('duracion_minutos', $disponibilidad->duracion_minutos ?? '') : ''" required class="mt-1 block w-full" /></div>
</div>
<p class="text-sm text-gray-600">Horas de Colombia (America/Bogota). Se generan solo intervalos completos; el tiempo sobrante queda sin intervalo. Esta configuración no reserva citas.</p>
<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirmacion" value="1" required class="rounded border-gray-300 text-teal-700" /> Confirmo las fechas y horarios de mi disponibilidad.</label>
