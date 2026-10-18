<!DOCTYPE html>
<html lang="pt-BR">
<head>
    @include('layouts.partials.head')
</head>
<body>
    <header class="app-header">
        <div class="app-header-inner">
            <a href="{{ route('dashboard') }}" class="brand" wire:navigate>
                <span class="brand-mark"><x-icon name="network" class="icon-sm" /></span>
                {{ config('app.name') }}
            </a>

            <div class="header-actions">
                @include('layouts.partials.theme-toggle')

                @auth
                    <div class="user-chip">
                        <span class="avatar">{{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}</span>
                        <span class="user-chip-text">
                            <span class="user-chip-name">{{ auth()->user()->name }}</span>
                            <span class="user-chip-email">{{ auth()->user()->email }}</span>
                        </span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="icon-btn" title="Sair" aria-label="Sair">
                                <x-icon name="logout" />
                            </button>
                        </form>
                    </div>
                @endauth
            </div>
        </div>
    </header>

    @include('layouts.partials.nav')

    <main class="app-main">
        {{ $slot }}
    </main>

    <footer class="app-footer">{{ config('app.name') }} · painel de controle</footer>

    @livewireScripts
</body>
</html>
