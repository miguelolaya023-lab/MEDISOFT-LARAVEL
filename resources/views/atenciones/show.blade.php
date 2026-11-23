<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Atención médica</h2></x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            <section class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
                @if (session('status'))
                    <p role="status" class="text-teal-700">{{ session('status') }}</p>
                @endif
                <x-input-error :messages="$errors->all()" />
                <h3 class="text-lg font-semibold">Cita #{{ $cita->id }} · {{ $cita->modalidad }} · {{ $cita->estado }}</h3>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="font-medium">Paciente</dt><dd>{{ $cita->paciente->nombres }} {{ $cita->paciente->apellidos }}</dd></div>
                    <div><dt class="font-medium">Horario de la cita (Colombia)</dt><dd>{{ $cita->horario() }}</dd></div>
                    @if ($atencion)
                        <div><dt class="font-medium">Atención #{{ $atencion->id }}</dt><dd>{{ $atencion->estado }}</dd></div>
                        <div><dt class="font-medium">Inicio (Colombia)</dt><dd>{{ $atencion->fecha_hora_inicio->timezone('America/Bogota')->format('Y-m-d H:i:s') }}</dd></div>
                        <div><dt class="font-medium">Historia clínica vinculada</dt><dd>#{{ $atencion->historia_clinica_id }}</dd></div>
                    @endif
                </dl>
                @if ($atencion)
                    @can('view', $atencion)
                        @include('atenciones.campos-clinicos')
                    @endcan
                @endif
                @if ($errorInicio)
                    @if (! $errors->has('cita'))
                        <x-input-error :messages="[$errorInicio]" />
                    @endif
                @elseif (! $atencion)
                    <form method="POST" action="{{ route('atenciones.store', $cita) }}">
                        @csrf
                        <x-primary-button>Iniciar atención</x-primary-button>
                    </form>
                @endif
                <a href="{{ route('atenciones.index') }}" class="inline-block text-teal-700 underline">Volver a mis citas</a>
            </section>
        </div>
    </div>
</x-app-layout>
