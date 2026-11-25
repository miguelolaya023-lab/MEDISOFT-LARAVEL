<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">Historial de pagos</h2></x-slot>

    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <form method="GET" action="{{ route('pacientes.historial-pagos.localizar') }}" class="space-y-5 rounded-lg bg-white p-5 shadow-sm">
            @isset($error)
                <x-input-error :messages="[$error]" role="alert" />
            @endisset
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="tipo_documento" value="Tipo de documento" />
                    <select name="tipo_documento" id="tipo_documento" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500">
                        <option value="">Seleccione un tipo</option>
                        @foreach (\App\Models\Paciente::TIPOS_DOCUMENTO as $codigo => $tipo)
                            <option value="{{ $codigo }}">{{ $tipo }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="documento" value="Número de documento" />
                    <x-text-input name="documento" id="documento" maxlength="30" autocomplete="off" class="mt-1 block w-full" required />
                </div>
            </div>
            <x-primary-button>Buscar paciente</x-primary-button>
        </form>
    </div>
</x-app-layout>
