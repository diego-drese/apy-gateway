<div class="card">
    <div class="toolbar">
        <h1>Certificados</h1>
        <a href="{{ route('certificates.upload') }}" class="btn" wire:navigate>Enviar</a>
    </div>

    @if ($certificates->isEmpty())
        <p class="empty">Nenhum certificado cadastrado.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Domínio</th>
                    <th>Provedor</th>
                    <th>Status</th>
                    <th>Expira em</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($certificates as $certificate)
                    <tr wire:key="certificate-{{ $certificate->id }}">
                        <td>{{ $certificate->primary_domain }}</td>
                        <td>{{ $certificate->provider->value }}</td>
                        <td>{{ $certificate->status->value }}</td>
                        <td>{{ $certificate->expires_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $certificates->links() }}
    @endif
</div>
