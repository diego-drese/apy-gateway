<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @include('layouts.partials.styles')
    @livewireStyles
</head>
<body>
    <div class="card card-narrow">
        {{ $slot }}
    </div>
    @livewireScripts
</body>
</html>
