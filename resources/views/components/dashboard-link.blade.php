@props(['href', 'icon', 'title'])

<a href="{{ $href }}" class="med-shortcut group flex min-w-0 flex-col gap-4 rounded-2xl border bg-white p-5">
    <div class="flex items-center justify-between gap-3">
        <span class="med-shortcut-icon inline-flex size-11 items-center justify-center rounded-xl">
            <x-shell-icon :name="$icon" />
        </span>
        <x-shell-icon name="arrow" class="med-shortcut-arrow" />
    </div>
    <div>
        <h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>
        <p class="mt-1 text-sm leading-relaxed text-slate-600">{{ $slot }}</p>
    </div>
</a>
