@props(['href', 'icon' => null, 'active' => false])

<a href="{{ $href }}" @if ($active) aria-current="page" @endif {{ $attributes->class(['med-nav-link', 'med-nav-link--child' => !$icon]) }}>
    @if ($icon)
        <x-shell-icon :name="$icon" />
    @endif
    <span>{{ $slot }}</span>
</a>
