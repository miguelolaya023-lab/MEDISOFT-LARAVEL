<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Confirmación de consulta particular</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-4xl px-4 sm:px-6"><section class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
        @if (session('status'))<p role="status" class="text-teal-700">{{ session('status') }}</p>@endif
        <h3 class="text-lg font-semibold">Cita #{{ $cita->id }} — {{ $cita->estado }}</h3>
        <dl class="grid gap-4 sm:grid-cols-2">
            <div><dt class="font-medium">Paciente</dt><dd>{{ $cita->paciente->nombres }} {{ $cita->paciente->apellidos }}</dd></div>
            <div><dt class="font-medium">Médico</dt><dd>{{ $cita->medico->user->nombres }} {{ $cita->medico->user->apellidos }}</dd></div>
            <div><dt class="font-medium">Fecha y horario (Colombia)</dt><dd>{{ $cita->fecha->toDateString() }} {{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</dd></div>
            <div><dt class="font-medium">Modalidad</dt><dd>{{ $cita->modalidad }}</dd></div>
            <div><dt class="font-medium">Valor base (COP)</dt><dd>{{ $cita->valor_base }}</dd></div>
            <div><dt class="font-medium">Preferencia al agendar</dt><dd>{{ $cita->tipo_paciente_al_agendar }}</dd></div>
            <div class="sm:col-span-2"><dt class="font-medium">Motivo administrativo</dt><dd class="whitespace-pre-wrap">{{ $cita->motivo_consulta }}</dd></div>
        </dl>
        <p class="text-sm text-gray-600">La cita no ha generado atención ni pago. Esta confirmación no es una agenda general.</p>
        <a href="{{ route('citas.create') }}" class="text-teal-700 underline">Agendar otra consulta particular</a>
    </section></div></div>
</x-app-layout>
