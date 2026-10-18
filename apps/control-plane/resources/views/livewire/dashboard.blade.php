<div>
    <div class="page-header">
        <div>
            <h1 class="page-title">Olá, {{ Str::before(auth()->user()->name, ' ') }}</h1>
            <p class="page-subtitle">Visão geral do gateway.</p>
        </div>
        <a href="{{ route('proxy-hosts.create') }}" class="btn" wire:navigate>
            <x-icon name="plus" class="icon-sm" /> Novo proxy host
        </a>
    </div>

    <div class="stat-grid">
        <a href="{{ route('proxy-hosts.index') }}" class="card stat" wire:navigate>
            <span class="stat-icon"><x-icon name="globe" /></span>
            <span>
                <span class="stat-value">{{ $proxyHostsTotal }}</span>
                <span class="stat-label">Proxy hosts · {{ $proxyHostsEnabled }} ativos</span>
            </span>
        </a>

        <a href="{{ route('certificates.index') }}" class="card stat" wire:navigate>
            <span @class(['stat-icon', 'stat-icon-success' => $certificatesNeedingAttention === 0, 'stat-icon-warning' => $certificatesNeedingAttention > 0])>
                <x-icon name="shield" />
            </span>
            <span>
                <span class="stat-value">{{ $certificatesValid }}</span>
                <span class="stat-label">
                    Certificados válidos
                    @if ($certificatesNeedingAttention > 0)
                        · {{ $certificatesNeedingAttention }} precisam de atenção
                    @endif
                </span>
            </span>
        </a>

        <div class="card stat">
            <span @class(['stat-icon', 'stat-icon-success' => $replicasTotal > 0 && $replicasOnline === $replicasTotal, 'stat-icon-warning' => $replicasOnline < $replicasTotal])>
                <x-icon name="server" />
            </span>
            <span>
                <span class="stat-value">{{ $replicasOnline }}/{{ $replicasTotal }}</span>
                <span class="stat-label">Réplicas online</span>
            </span>
        </div>

        <a href="{{ route('users.index') }}" class="card stat" wire:navigate>
            <span class="stat-icon"><x-icon name="users" /></span>
            <span>
                <span class="stat-value">{{ $usersTotal }}</span>
                <span class="stat-label">Usuários</span>
            </span>
        </a>
    </div>
</div>
