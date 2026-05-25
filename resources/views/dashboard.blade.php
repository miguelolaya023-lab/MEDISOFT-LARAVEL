<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Panel de MEDISOFT
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <p class="text-sm font-semibold uppercase tracking-wide text-teal-700">Sesión iniciada</p>
                    <h3 class="mt-2 text-2xl font-semibold text-gray-900">
                        Bienvenido, {{ Auth::user()->name }}
                    </h3>
                    <p class="mt-3 max-w-3xl text-sm text-gray-600">
                        Has ingresado correctamente al área autenticada de MEDISOFT con el correo
                        <span class="font-medium text-gray-900">{{ Auth::user()->email }}</span>.
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
