@props(['name'])

<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" {{ $attributes->class('size-5 shrink-0') }}>
    @switch($name)
        @case('home')
            <path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2h-4v-7H9v7H5a2 2 0 0 1-2-2z"/>
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18m-13 5h2m4 0h2"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
            @break
        @case('people')
            <circle cx="9" cy="8" r="3"/><path d="M3 20v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2m1-15a3 3 0 0 1 0 6m2 3a4 4 0 0 1 3 4v2"/>
            @break
        @case('clinical')
            <rect x="4" y="5" width="16" height="16" rx="2"/><path d="M9 5V3h6v2m-3 4v8m-4-4h8"/>
            @break
        @case('shield')
            <path d="M12 22s7-3 7-9V5l-7-3-7 3v8c0 6 7 9 7 9Zm-3-10 2 2 4-4"/>
            @break
        @case('document')
            <path d="M13 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10Zm0 0v7h7M8 14h8m-8 3h6"/>
            @break
        @case('message')
            <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>
            @break
        @case('profile')
            <circle cx="12" cy="8" r="3"/><path d="M5 21v-2a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v2"/>
            @break
        @case('logout')
            <path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5m6-12 4 4-4 4m4-4H9"/>
            @break
        @case('arrow')
            <path d="M5 12h14m-6-6 6 6-6 6"/>
            @break
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16"/>
            @break
        @case('close')
            <path d="m6 6 12 12M6 18 18 6"/>
            @break
        @case('chevron')
            <path d="m6 9 6 6 6-6"/>
            @break
    @endswitch
</svg>
