<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>MEDISOFT</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <a href="#medisoft-content" class="med-skip-link">Saltar al contenido</a>

        <div class="med-shell min-h-screen" x-data="medisoftShell">
            @include('layouts.navigation')

            <div class="min-w-0">
                <header class="med-topbar">
                    <div class="med-topbar-inner">
                        <div class="flex min-w-0 items-center gap-3">
                            <button type="button" class="med-drawer-toggle med-icon-button" aria-label="Abrir navegación"
                                aria-controls="medisoft-navigation" aria-expanded="false" :aria-expanded="drawerOpen.toString()"
                                x-ref="toggle" @click="openDrawer">
                                <x-shell-icon name="menu" />
                            </button>
                            <div class="min-w-0">
                                <span class="med-topbar-brand">MEDISOFT</span>
                                <div class="med-screen-title break-words">
                                    {{ $header ?? 'Área de trabajo' }}
                                </div>
                            </div>
                        </div>

                        <details class="med-account" x-data @click.outside="$el.open = false"
                            @keydown.escape.stop="if ($el.open) { $el.open = false; $refs.accountTrigger.focus(); }">
                            <summary x-ref="accountTrigger" class="med-account-trigger">
                                <span class="med-avatar" aria-hidden="true">{{ Str::of(Auth::user()->name)->substr(0, 1)->upper() }}</span>
                                <span class="hidden max-w-[11rem] truncate sm:block">{{ Auth::user()->name }}</span>
                                <span class="sr-only sm:hidden">Cuenta de {{ Auth::user()->name }}</span>
                                <x-shell-icon name="chevron" class="med-account-chevron" />
                            </summary>
                            <div class="med-account-panel">
                                <p class="break-words px-3 py-2 text-sm font-semibold text-slate-900">{{ Auth::user()->name }}</p>
                                <a href="{{ route('profile.edit') }}" class="med-account-action">
                                    <x-shell-icon name="profile" /> Perfil
                                </a>
                                <form method="POST" action="{{ route('logout') }}" onsubmit="return window.confirm('¿Cerrar sesión?')">
                                    @csrf
                                    <button type="submit" class="med-account-action">
                                        <x-shell-icon name="logout" /> Cerrar sesión
                                    </button>
                                </form>
                            </div>
                        </details>
                    </div>
                </header>

                <main id="medisoft-content" tabindex="-1" class="min-w-0">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
