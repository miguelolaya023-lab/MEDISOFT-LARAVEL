@php
    $puedeFinalizar = auth()->user()->can('finalizar', $atencion);
    $enCurso = $atencion->estado === 'EN_CURSO';
@endphp

@foreach ([
    'motivo_consulta' => ['Motivo clínico de consulta', 'registrarMotivo', 'atenciones.clinica.motivo', 'Guardar motivo'],
    'diagnostico_principal' => ['Diagnóstico principal', 'registrarDiagnostico', 'atenciones.clinica.diagnostico', 'Guardar diagnóstico'],
] as $campo => [$etiqueta, $permiso, $ruta, $boton])
    @php($puedeEditar = auth()->user()->can($permiso, $atencion))
    @if ($enCurso && $puedeEditar)
        <form method="POST" action="{{ route($ruta, $atencion) }}" class="space-y-2">
            @csrf
            @method('PATCH')
            <input type="hidden" name="version" value="{{ $atencion->versionClinica($campo) }}">
            <x-input-label :for="$campo" :value="$etiqueta" />
            <textarea id="{{ $campo }}" name="{{ $campo }}" rows="3" required class="block w-full rounded-md border-gray-300">{{ $atencion->$campo }}</textarea>
            <x-primary-button>{{ $boton }}</x-primary-button>
        </form>
    @elseif ($puedeEditar || $puedeFinalizar)
        <div><h4 class="font-medium">{{ $etiqueta }}</h4><p class="whitespace-pre-wrap">{{ $atencion->$campo ?? 'Pendiente de registro' }}</p></div>
    @endif
@endforeach

@if ($puedeFinalizar && $enCurso)
    <form method="POST" action="{{ route('atenciones.clinica.finalizar', $atencion) }}" class="space-y-3 border-t pt-4">
        @csrf
        <input type="hidden" name="version" value="{{ $atencion->versionClinica('cierre') }}">
        <x-input-label for="observacion_final" value="Observación final (opcional)" />
        <textarea id="observacion_final" name="observacion_final" rows="3" class="block w-full rounded-md border-gray-300"></textarea>
        <label class="block">
            <input type="checkbox" name="confirmacion" value="1" required class="rounded border-gray-300 text-teal-700">
            Confirmo el cierre con el motivo y el diagnóstico guardados.
        </label>
        <x-primary-button>Finalizar atención</x-primary-button>
    </form>
@elseif ($atencion->estado === 'FINALIZADA')
    <dl class="space-y-2 border-t pt-4">
        <div><dt class="font-medium">Finalización (Colombia)</dt><dd>{{ $atencion->fecha_hora_fin?->timezone('America/Bogota')->format('Y-m-d H:i:s') }}</dd></div>
        @if ($puedeFinalizar && $atencion->observacion_final !== null)
            <div><dt class="font-medium">Observación final</dt><dd class="whitespace-pre-wrap">{{ $atencion->observacion_final }}</dd></div>
        @endif
    </dl>
@endif
