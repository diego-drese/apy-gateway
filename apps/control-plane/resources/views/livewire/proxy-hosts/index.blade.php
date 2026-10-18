<div>
    <div class="page-header">
        <div>
            <h1 class="page-title">Proxy Hosts</h1>
            <p class="page-subtitle">Domínios publicados e para onde cada um encaminha.</p>
        </div>
        <a href="{{ route('proxy-hosts.create') }}" class="btn" wire:navigate>
            <x-icon name="plus" class="icon-sm" /> Novo proxy host
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Proxy hosts <span class="count-pill">{{ $proxyHosts->total() }}</span></h2>
            <input type="search" class="input search-input" placeholder="Buscar domínio…"
                   wire:model.live.debounce.300ms="search" aria-label="Buscar domínio">
        </div>

        @if ($proxyHosts->isEmpty())
            <div class="empty-state">
                <x-icon name="globe" />
                @if (trim($search) !== '')
                    <div class="empty-state-title">Nenhum domínio encontrado</div>
                    Nada corresponde a “{{ $search }}”.
                @else
                    <div class="empty-state-title">Nenhum proxy host ainda</div>
                    Crie o primeiro para começar a publicar domínios.
                @endif
            </div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Origem</th>
                            <th>Destino</th>
                            <th>SSL</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($proxyHosts as $proxyHost)
                            <tr wire:key="proxy-host-{{ $proxyHost->id }}">
                                <td>
                                    <div class="cell-primary">{{ $proxyHost->domain }}</div>
                                    <div class="cell-secondary">
                                        Criado em {{ $proxyHost->created_at?->format('d/m/Y') }}
                                        @if ($proxyHost->websockets_enabled) · WebSockets @endif
                                    </div>
                                </td>
                                <td class="mono">{{ $proxyHost->forward_scheme->value }}://{{ $proxyHost->forward_host }}:{{ $proxyHost->forward_port }}</td>
                                <td>
                                    @if ($proxyHost->sslCertificate)
                                        <x-certificate-status :status="$proxyHost->sslCertificate->status" />
                                        <div class="cell-secondary">
                                            {{ $proxyHost->sslCertificate->provider === \App\Enums\SslCertificateProvider::LetsEncrypt ? "Let's Encrypt" : 'Personalizado' }}
                                        </div>
                                    @else
                                        <span class="badge badge-plain">Somente HTTP</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($proxyHost->enabled)
                                        <span class="badge badge-success">Online</span>
                                    @else
                                        <span class="badge">Desabilitado</span>
                                    @endif
                                </td>
                                <td class="cell-actions">
                                    <a href="{{ route('proxy-hosts.edit', $proxyHost) }}" class="icon-btn" title="Editar" aria-label="Editar" wire:navigate>
                                        <x-icon name="pencil" class="icon-sm" />
                                    </a>
                                    <button type="button" class="icon-btn icon-btn-danger" title="Apagar" aria-label="Apagar"
                                            wire:click="delete({{ $proxyHost->id }})"
                                            wire:confirm="Apagar o proxy host {{ $proxyHost->domain }}?">
                                        <x-icon name="trash" class="icon-sm" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $proxyHosts->links('partials.pagination') }}
        @endif
    </div>
</div>
