@php
    $zona = \App\Services\ConsultarHistoriaClinica::ZONA_CONSULTORIO;
    $autor = $atencion->medico?->user?->name ?? 'Autor no disponible';
@endphp
<article class="grid gap-4 rounded-lg border border-gray-200 p-4" aria-labelledby="historia-atencion-{{ $atencion->id }}">
    <header class="grid gap-1">
        <h3 id="historia-atencion-{{ $atencion->id }}" class="font-semibold text-gray-900">Atención #{{ $atencion->id }}</h3>
        <p class="break-words text-sm text-gray-600">
            {{ $atencion->fecha_hora_inicio->timezone($zona)->format('d/m/Y H:i') }} · {{ $autor }} · {{ $atencion->cita->modalidad }} · {{ $atencion->estado }}
        </p>
    </header>
    <dl class="grid gap-3 break-words sm:grid-cols-2">
        <div><dt class="font-medium">Motivo de consulta</dt><dd class="whitespace-pre-wrap text-gray-700">{{ $atencion->motivo_consulta ?? 'Sin registrar' }}</dd></div>
        <div><dt class="font-medium">Diagnóstico</dt><dd class="whitespace-pre-wrap text-gray-700">{{ $atencion->diagnostico_principal ?? 'Sin registrar' }}</dd></div>
    </dl>

    <section class="grid gap-2 border-t border-gray-200 pt-3">
        <h4 class="font-semibold">Tratamientos</h4>
        @forelse ($atencion->tratamientos as $tratamiento)
            <div class="grid gap-1 break-words rounded-md bg-gray-50 p-3">
                <p class="text-sm text-gray-600">{{ $tratamiento->fecha_registro->timezone($zona)->format('d/m/Y H:i') }} · {{ $autor }}</p>
                <p class="whitespace-pre-wrap text-gray-800">{{ $tratamiento->descripcion }}</p>
                @if ($tratamiento->revisiones->isNotEmpty())
                    <details>
                        <summary class="cursor-pointer text-sm font-medium text-violet-800">Revisiones del tratamiento</summary>
                        <ul class="grid gap-2 py-2">
                            @foreach ($tratamiento->revisiones as $revision)
                                <li>
                                    <p class="text-sm text-gray-600">{{ $revision->fecha_correccion->timezone($zona)->format('d/m/Y H:i') }} · {{ $revision->autor?->name ?? 'Autor no disponible' }}</p>
                                    <p class="whitespace-pre-wrap text-gray-800">{{ $revision->descripcion_anterior }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-600">Esta atención no tiene tratamientos registrados.</p>
        @endforelse
    </section>

    <section class="grid gap-2 border-t border-gray-200 pt-3">
        <h4 class="font-semibold">Fórmulas médicas</h4>
        @forelse ($atencion->formulasMedicas as $formula)
            <div class="grid gap-2 break-words rounded-md bg-gray-50 p-3">
                <p class="text-sm text-gray-600">{{ $formula->fecha_emision->timezone($zona)->format('d/m/Y H:i') }} · {{ $autor }}</p>
                @if ($formula->observacion !== null)
                    <p class="whitespace-pre-wrap text-gray-800">{{ $formula->observacion }}</p>
                @endif
                <ol class="grid gap-2">
                    @foreach ($formula->detalles as $detalle)
                        <li class="rounded-md border border-gray-200 bg-white p-3">
                            <p class="font-medium">{{ $detalle->posicion }}. {{ $detalle->medicamento }}</p>
                            <dl class="grid gap-1 text-sm sm:grid-cols-2">
                                <div><dt class="font-medium">Dosis</dt><dd>{{ $detalle->dosis }}</dd></div>
                                @foreach (['frecuencia' => 'Frecuencia', 'duracion' => 'Duración', 'indicaciones' => 'Indicaciones'] as $campo => $etiqueta)
                                    @if ($detalle->$campo !== null)
                                        <div><dt class="font-medium">{{ $etiqueta }}</dt><dd class="whitespace-pre-wrap">{{ $detalle->$campo }}</dd></div>
                                    @endif
                                @endforeach
                            </dl>
                        </li>
                    @endforeach
                </ol>
            </div>
        @empty
            <p class="text-sm text-gray-600">Esta atención no tiene fórmulas registradas.</p>
        @endforelse
    </section>
</article>
