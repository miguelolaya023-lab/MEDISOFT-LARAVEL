@if (!isset($remision))
    <div><x-input-label for="paciente_id" value="Paciente ACTIVO" />
        <select id="paciente_id" name="paciente_id" required class="mt-1 block w-full rounded-md border-gray-300"><option value="">Seleccione</option>
            @foreach ($pacientes as $paciente)<option value="{{ $paciente->id }}" @selected((is_scalar(old('paciente_id')) ? (string) old('paciente_id') : '') === (string) $paciente->id)>{{ $paciente->tipo_documento }} {{ $paciente->numero_documento }} — {{ $paciente->nombres }} {{ $paciente->apellidos }}</option>@endforeach
        </select></div>
    <div><x-input-label for="eps_id" value="EPS ACTIVA" />
        <select id="eps_id" name="eps_id" required class="mt-1 block w-full rounded-md border-gray-300"><option value="">Seleccione</option>
            @foreach ($epsOptions as $eps)<option value="{{ $eps->id }}" @selected((is_scalar(old('eps_id')) ? (string) old('eps_id') : '') === (string) $eps->id)>{{ $eps->nit }} — {{ $eps->nombre }}</option>@endforeach
        </select></div>
    <div><x-input-label for="numero_remision" value="Número de remisión" /><x-text-input id="numero_remision" name="numero_remision" :value="is_string(old('numero_remision')) ? old('numero_remision') : ''" maxlength="100" required class="mt-1 block w-full" /></div>
    <div><x-input-label for="fecha_remision" value="Fecha de emisión" /><x-text-input id="fecha_remision" name="fecha_remision" type="date" :value="is_string(old('fecha_remision')) ? old('fecha_remision') : ''" :max="\App\Models\RemisionEps::hoy()" required class="mt-1 block w-full" /></div>
@endif
<div><x-input-label for="servicio_autorizado" value="Servicio autorizado" /><textarea id="servicio_autorizado" name="servicio_autorizado" required maxlength="1000" @readonly(isset($remision) && $remision->estado === 'USADA') class="mt-1 block w-full rounded-md border-gray-300">{{ is_string(old('servicio_autorizado', $remision->servicio_autorizado ?? '')) ? old('servicio_autorizado', $remision->servicio_autorizado ?? '') : '' }}</textarea></div>
<div><x-input-label for="vigencia_hasta" value="Vigencia hasta (inclusive)" /><x-text-input id="vigencia_hasta" name="vigencia_hasta" type="date" :value="is_string(old('vigencia_hasta', isset($remision) ? $remision->vigencia_hasta->toDateString() : '')) ? old('vigencia_hasta', isset($remision) ? $remision->vigencia_hasta->toDateString() : '') : ''" :readonly="isset($remision) && $remision->estado === 'USADA'" required class="mt-1 block w-full" /></div>
<div><x-input-label for="observacion" value="Observación (opcional)" /><textarea id="observacion" name="observacion" maxlength="2000" class="mt-1 block w-full rounded-md border-gray-300">{{ is_string(old('observacion', $remision->observacion ?? '')) ? old('observacion', $remision->observacion ?? '') : '' }}</textarea></div>
@if (isset($remision))
<div><x-input-label for="motivo" value="Justificación (obligatoria al corregir una vigencia vencida para volver a VIGENTE)" /><textarea id="motivo" name="motivo" maxlength="500" class="mt-1 block w-full rounded-md border-gray-300">{{ is_string(old('motivo')) ? old('motivo') : '' }}</textarea></div>
@endif
