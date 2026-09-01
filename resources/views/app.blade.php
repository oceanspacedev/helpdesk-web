<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="fi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        (() => {
            const theme = localStorage.getItem('theme') ?? 'system';
            window.theme = theme;
            if (
                theme === 'dark'
                || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
            ) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Form publik untuk melaporkan kendala ke Helpdesk.">
    <link rel="icon" href="{{ asset('images/icon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('fonts/filament/filament/inter/index.css') }}">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap">
    <title inertia>{{ config('app.name', 'Helpdesk') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-inertia::head />
</head>
<body class="fi-body">
    <x-inertia::app />
</body>
</html>
