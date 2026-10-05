<x-mail::layout title="MEDISOFT" language="es">
<x-slot:header>
<x-mail::header :url="config('app.url')">
MEDISOFT
</x-mail::header>
</x-slot:header>

# {{ $greeting }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach

<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

Saludos,<br>
MEDISOFT

<x-slot:subcopy>
<x-mail::subcopy>
Si tienes dificultades para hacer clic en el botón «{{ $actionText }}», copia y pega la siguiente URL en tu navegador:
<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
</x-slot:subcopy>

<x-slot:footer>
<x-mail::footer>
© {{ now()->year }} MEDISOFT. Todos los derechos reservados.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
