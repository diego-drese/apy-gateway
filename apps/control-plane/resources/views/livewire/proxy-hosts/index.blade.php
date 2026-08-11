<div class="card">
    <div class="toolbar">
        <h1>Proxy Hosts</h1>
        <a href="{{ route('proxy-hosts.create') }}" class="btn" wire:navigate>Novo</a>
    </div>

    @if ($proxyHosts->isEmpty())
        <p class="empty">Nenhum proxy host cadastrado.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Domínio</th>
                    <th>Destino</th>
                    <th>SSL</th>
                    <th>Websockets</th>
                    <th>Habilitado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($proxyHosts as $proxyHost)
                    <tr wire:key="proxy-host-{{ $proxyHost->id }}">
                        <td>{{ $proxyHost->domain }}</td>
                        <td>{{ $proxyHost->forward_scheme->value }}://{{ $proxyHost->forward_host }}:{{ $proxyHost->forward_port }}</td>
                        <td>{{ $proxyHost->sslCertificate?->primary_domain ?? '—' }}</td>
                        <td>{{ $proxyHost->websockets_enabled ? 'Sim' : 'Não' }}</td>
                        <td>{{ $proxyHost->enabled ? 'Sim' : 'Não' }}</td>
                        <td>
                            <a href="{{ route('proxy-hosts.edit', $proxyHost) }}" wire:navigate>Editar</a>
                            <button type="button" class="btn btn-danger" wire:click="delete({{ $proxyHost->id }})" wire:confirm="Apagar este proxy host?">Apagar</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $proxyHosts->links() }}
    @endif
</div>
