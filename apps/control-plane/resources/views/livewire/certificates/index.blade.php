<div>
    <div class="page-header">
        <div>
            <h1 class="page-title">Certificados SSL</h1>
            <p class="page-subtitle">
                Certificados Let's Encrypt são solicitados no formulário do proxy host (aba SSL).
                Aqui você envia certificados próprios, como um wildcard.
            </p>
        </div>
        <a href="{{ route('certificates.upload') }}" class="btn" wire:navigate>
            <x-icon name="upload" class="icon-sm" /> Enviar certificado
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Certificados <span class="count-pill">{{ $certificates->total() }}</span></h2>
        </div>

        @if ($certificates->isEmpty())
            <div class="empty-state">
                <x-icon name="shield" />
                <div class="empty-state-title">Nenhum certificado ainda</div>
                Envie um certificado ou solicite um Let's Encrypt ao criar um proxy host.
            </div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Domínios</th>
                            <th>Provedor</th>
                            <th>Status</th>
                            <th>Expira em</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($certificates as $certificate)
                            <tr wire:key="certificate-{{ $certificate->id }}">
                                <td>
                                    <div class="cell-primary">{{ $certificate->primary_domain }}</div>
                                    @if (count($certificate->domain_names ?? []) > 1)
                                        <div class="cell-secondary">+{{ count($certificate->domain_names) - 1 }} domínio(s)</div>
                                    @endif
                                </td>
                                <td>{{ $certificate->provider === \App\Enums\SslCertificateProvider::LetsEncrypt ? "Let's Encrypt" : 'Personalizado' }}</td>
                                <td><x-certificate-status :status="$certificate->status" /></td>
                                <td>{{ $certificate->expires_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $certificates->links('partials.pagination') }}
        @endif
    </div>
</div>
