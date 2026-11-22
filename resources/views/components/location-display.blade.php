@props(['registro', 'detalle' => false])
@if ($registro->departamento_codigo !== null && $registro->municipio_codigo !== null)
    <span class="block">{{ $registro->municipio->nombre }} · {{ $registro->departamento->nombre }}</span>
    <span class="block text-xs text-gray-500">Ubicación vigente oficial</span>
    @if ($detalle)
        @if (mb_strtolower(trim($registro->ciudad)) !== mb_strtolower(trim($registro->municipio->nombre)))
            <span class="mt-2 block text-sm text-gray-600">Ciudad registrada (histórica): {{ $registro->ciudad }}</span>
        @else
            <span class="mt-2 block text-xs text-gray-500">Ciudad registrada (histórica): coincide con el municipio.</span>
        @endif
    @endif
@else
    <span class="block">{{ $registro->ciudad !== '' ? $registro->ciudad : 'Sin registrar' }}</span>
    <span class="block text-xs text-gray-500">Ubicación histórica sin regularizar</span>
@endif
