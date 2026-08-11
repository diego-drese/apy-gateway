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
    <nav class="nav">
        <a href="{{ route('proxy-hosts.index') }}">Proxies</a>
        <a href="{{ route('certificates.index') }}">Certificados</a>
        <a href="{{ route('users.index') }}">Usuários</a>
        <a href="{{ route('ip-allowlist.index') }}">IPs</a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Sair</button>
        </form>
    </nav>

    <div class="container">
        {{ $slot }}
    </div>

    @livewireScripts
</body>
</html>
