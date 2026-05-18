<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'MITHQAL POS Admin') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body class="font-sans antialiased">
        @php
            $errors = $errors ?? null;
            $errorBag = $errors?->getBag('default');
            $flashedErrors = $errorBag && $errorBag->isNotEmpty() ? $errorBag->messages() : null;
            $flashedOld = session()->getOldInput() ?: null;
        @endphp

        <script>
            window.__SERVER_FLASH__ = Object.freeze({
                errors: @json($flashedErrors),
                old: @json($flashedOld),
            });
        </script>

        <div id="app"></div>
    </body>
</html>
