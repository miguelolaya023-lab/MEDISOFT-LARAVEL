<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Evidencia de notificación</h2></x-slot>

    <div class="py-12">
        <section class="mx-auto max-w-3xl space-y-5 rounded-lg bg-white p-6 shadow-sm">
            <p class="rounded-md bg-teal-50 p-3 text-sm text-teal-900">Simulación interna. No se envió ningún correo ni mensaje al paciente.</p>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-semibold text-gray-900">Intento #{{ $evidencia->id }} · Cita #{{ $cita->id }}</h3>
                <span class="rounded-full px-3 py-1 text-sm font-semibold {{ $evidencia->estado === 'SIMULADA' ? 'bg-teal-100 text-teal-900' : 'bg-amber-100 text-amber-900' }}">{{ $evidencia->estado }}</span>
            </div>
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="font-semibold">Canal</dt><dd>{{ $evidencia->canal }}</dd></div>
                <div><dt class="font-semibold">Fecha del intento (Colombia)</dt><dd>{{ $evidencia->fecha_intento->timezone('America/Bogota')->format('d/m/Y H:i:s') }}</dd></div>
                <div class="break-words"><dt class="font-semibold">Destino</dt><dd>{{ $evidencia->contacto_destino ?? 'Sin contacto válido' }}</dd></div>
                <div><dt class="font-semibold">Asunto</dt><dd>{{ $evidencia->asunto }}</dd></div>
                <div class="sm:col-span-2"><dt class="font-semibold">Mensaje</dt><dd>{{ $evidencia->mensaje }}</dd></div>
                @if ($evidencia->motivo_fallo)
                    <div class="sm:col-span-2"><dt class="font-semibold">Motivo</dt><dd>{{ $evidencia->motivo_fallo }}</dd></div>
                @endif
            </dl>
            <a class="inline-block font-medium text-teal-700 underline" href="{{ route('notificaciones.citas.index', ['cita_id' => $cita->id]) }}">Volver a la cita y su historial</a>
        </section>
    </div>
</x-app-layout>
