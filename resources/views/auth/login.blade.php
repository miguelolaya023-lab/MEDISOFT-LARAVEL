<x-guest-layout>
    <x-slot:loginContent>
        <main class="med-login-page px-4 py-6 sm:px-6 sm:py-10" aria-labelledby="med-login-title">
            <div class="med-login-card grid w-full max-w-[53rem] overflow-hidden rounded-[1.5rem] border bg-white md:grid-cols-[0.82fr_1fr]">
                <div class="med-login-brand relative isolate flex items-center overflow-hidden px-5 py-5 sm:px-8 md:p-9">
                    <div class="med-login-orbit" aria-hidden="true"></div>
                    <a href="/" class="relative inline-flex min-w-0 items-center gap-3 rounded-xl md:flex-col md:items-start md:gap-5">
                        <span class="med-sidebar-mark md:size-14"><x-application-logo class="size-7 fill-current md:size-9" /></span>
                        <span class="min-w-0">
                            <span class="block text-lg font-semibold tracking-tight md:text-2xl">MEDISOFT</span>
                            <span class="block text-xs text-violet-200 md:mt-1 md:text-sm">Gestión del consultorio</span>
                        </span>
                    </a>
                </div>

                <div class="min-w-0 px-5 py-7 sm:px-8 md:px-10 md:py-10">
                    <div class="mb-6">
                        <h1 id="med-login-title" class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">Iniciar sesión</h1>
                        <p class="mt-2 max-w-sm text-sm leading-relaxed text-slate-600">
                            Accede con tu correo electrónico o documento y contraseña.
                        </p>
                    </div>

                    <x-auth-session-status class="mb-4" :status="session('status')" />

                    <form method="POST" action="{{ route('login') }}" class="grid gap-4">
                        @csrf

                        <div>
                            <x-input-label for="identificador" value="Correo electrónico o documento" />
                            <x-text-input id="identificador" class="med-login-input mt-1.5 block w-full"
                                type="text" name="identificador" :value="old('identificador')" required autofocus autocomplete="username"
                                :aria-invalid="$errors->has('identificador') ? 'true' : null"
                                :aria-describedby="$errors->has('identificador') ? 'med-login-identifier-error' : null" />
                            <x-input-error id="med-login-identifier-error" :messages="$errors->get('identificador')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="password" value="Contraseña" />
                            <x-text-input id="password" class="med-login-input mt-1.5 block w-full"
                                type="password" name="password" required autocomplete="current-password"
                                :aria-invalid="$errors->has('password') ? 'true' : null"
                                :aria-describedby="$errors->has('password') ? 'med-login-password-error' : null" />
                            <x-input-error id="med-login-password-error" :messages="$errors->get('password')" class="mt-2" />
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-x-5 gap-y-2">
                            <label for="remember_me" class="inline-flex min-h-9 cursor-pointer items-center gap-2">
                                <input id="remember_me" type="checkbox" class="med-login-remember" name="remember">
                                <span class="text-sm text-slate-700">Recordarme</span>
                            </label>

                            @if (Route::has('password.request'))
                                <a class="med-login-recovery inline-flex min-h-9 items-center rounded-md text-sm font-semibold" href="{{ route('password.request') }}">
                                    ¿Olvidaste tu contraseña?
                                </a>
                            @endif
                        </div>

                        <button type="submit" class="med-login-submit min-h-11 rounded-xl px-4 py-2.5 text-sm font-semibold">
                            Entrar
                        </button>
                    </form>
                </div>
            </div>
        </main>
    </x-slot:loginContent>
</x-guest-layout>
