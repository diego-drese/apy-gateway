<!DOCTYPE html>
<html lang="pt-BR">
<head>
    @include('layouts.partials.head')
</head>
<body>
    @include('layouts.partials.theme-toggle', ['class' => 'guest-theme'])

    <div class="guest-shell">
        <div class="guest-card">
            <div class="brand guest-brand">
                <span class="brand-mark"><x-icon name="network" class="icon-sm" /></span>
                {{ config('app.name') }}
            </div>
            <div class="card">
                <div class="card-body">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
