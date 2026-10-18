<div>
    <div class="page-header">
        <div>
            <h1 class="page-title">Editar proxy host</h1>
            <p class="page-subtitle">{{ $proxyHost->domain }}</p>
        </div>
    </div>

    @include('livewire.proxy-hosts.partials.form', [
        'submitLabel' => 'Salvar alterações',
        'currentCertificate' => $proxyHost->sslCertificate,
    ])
</div>
