<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <div class="mb-6 text-center">
        <p class="text-sm font-semibold uppercase tracking-wide text-teal-700">MEDISOFT</p>
        <h1 class="mt-2 text-2xl font-semibold text-gray-900">Iniciar sesión</h1>
        <p class="mt-2 text-sm text-gray-600">
            Accede con tu correo electrónico y contraseña para continuar al panel administrativo.
        </p>
    </div>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div>
            <x-input-label for="email" value="Correo electrónico" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" value="Contraseña" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-teal-700 shadow-sm focus:ring-teal-600" name="remember">
                <span class="ms-2 text-sm text-gray-600">Recordarme</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('password.request') }}">
                    ¿Olvidaste tu contraseña?
                </a>
            @endif

            <button type="submit" class="ms-3 inline-flex items-center rounded-md border border-transparent bg-teal-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-teal-800 focus:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 active:bg-teal-900">
                Entrar
            </button>
        </div>
    </form>
</x-guest-layout>
