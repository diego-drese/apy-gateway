{{--
    Shared by proxy-hosts/create and proxy-hosts/edit. Two tabs, like NPM's proxy host modal:
    "Detalhes" (origin → destination) and "SSL" (none / existing certificate / request a new one).
    Expects: $certificates, $submitLabel, and optionally $currentCertificate (edit only).
--}}
<form wire:submit="save" class="card form-card" x-data="{ tab: 'details' }">
    <div class="tabs" role="tablist">
        <button type="button" class="tab" :class="{ 'is-active': tab === 'details' }" @click="tab = 'details'" role="tab">
            <x-icon name="globe" class="icon-sm" /> Detalhes
            @if ($errors->hasAny(['domain', 'forward_scheme', 'forward_host', 'forward_port']))
                <span class="badge badge-danger badge-plain">!</span>
            @endif
        </button>
        <button type="button" class="tab" :class="{ 'is-active': tab === 'ssl' }" @click="tab = 'ssl'" role="tab">
            <x-icon name="lock" class="icon-sm" /> SSL
            @if ($errors->hasAny(['ssl_option', 'ssl_certificate_id']))
                <span class="badge badge-danger badge-plain">!</span>
            @endif
        </button>
    </div>

    <div class="card-body" x-show="tab === 'details'">
        <div class="field">
            <label class="label" for="domain">Domínio</label>
            <input id="domain" type="text" class="input" wire:model="domain" placeholder="app.exemplo.com.br" autofocus>
            @error('domain') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <span class="label">Encaminhar para</span>
            <div class="field-row">
                <select class="select" wire:model="forward_scheme" aria-label="Esquema">
                    <option value="http">http</option>
                    <option value="https">https</option>
                </select>
                <input type="text" class="input" wire:model="forward_host" placeholder="nome-do-servico ou 10.0.0.5" aria-label="Host de destino">
                <input type="number" class="input" wire:model="forward_port" placeholder="80" aria-label="Porta de destino">
            </div>
            @error('forward_scheme') <p class="error">{{ $message }}</p> @enderror
            @error('forward_host') <p class="error">{{ $message }}</p> @enderror
            @error('forward_port') <p class="error">{{ $message }}</p> @enderror
        </div>

        <label class="switch">
            <span class="switch-text">
                <span class="switch-title">Suporte a WebSockets</span>
                <span class="hint">Repassa os cabeçalhos Upgrade/Connection para o destino.</span>
            </span>
            <input type="checkbox" wire:model="websockets_enabled">
        </label>

        <label class="switch">
            <span class="switch-text">
                <span class="switch-title">Habilitado</span>
                <span class="hint">Desabilitado, o domínio deixa de ser servido pelas réplicas.</span>
            </span>
            <input type="checkbox" wire:model="enabled">
        </label>
    </div>

    <div class="card-body" x-show="tab === 'ssl'" x-cloak>
        @isset($currentCertificate)
            <div class="alert">
                <x-icon name="shield" />
                <span>
                    Certificado atual: <strong>{{ $currentCertificate->primary_domain }}</strong>
                    <x-certificate-status :status="$currentCertificate->status" />
                    @if ($currentCertificate->expires_at)
                        · expira em {{ $currentCertificate->expires_at->format('d/m/Y') }}
                    @endif
                </span>
            </div>
        @endisset

        <div class="choice-list">
            <label class="choice">
                <input type="radio" wire:model.live="ssl_option" value="none">
                <span>
                    <span class="choice-title">Sem SSL</span><br>
                    <span class="choice-text">Somente HTTP.</span>
                </span>
            </label>

            <label class="choice">
                <input type="radio" wire:model.live="ssl_option" value="existing">
                <span>
                    <span class="choice-title">Usar um certificado existente</span><br>
                    <span class="choice-text">Um certificado enviado manualmente ou emitido antes (ex.: wildcard).</span>
                </span>
            </label>

            <label class="choice">
                <input type="radio" wire:model.live="ssl_option" value="request">
                <span>
                    <span class="choice-title">Solicitar novo certificado Let's Encrypt</span><br>
                    <span class="choice-text">
                        Emitido logo após salvar (o desafio HTTP-01 precisa do proxy já ativo) e anexado
                        automaticamente quando ficar válido. O DNS do domínio precisa apontar para o gateway.
                    </span>
                </span>
            </label>
        </div>
        @error('ssl_option') <p class="error">{{ $message }}</p> @enderror

        @if ($ssl_option === 'existing')
            <div class="field field-spaced">
                <label class="label" for="ssl_certificate_id">Certificado</label>
                <select id="ssl_certificate_id" class="select" wire:model="ssl_certificate_id">
                    <option value="">Selecione…</option>
                    @foreach ($certificates as $certificate)
                        <option value="{{ $certificate->id }}">
                            {{ $certificate->primary_domain }} ({{ $certificate->status->value }})
                        </option>
                    @endforeach
                </select>
                @error('ssl_certificate_id') <p class="error">{{ $message }}</p> @enderror
                @if ($certificates->isEmpty())
                    <p class="hint">
                        Nenhum certificado cadastrado.
                        <a href="{{ route('certificates.upload') }}" wire:navigate>Enviar um certificado</a>.
                    </p>
                @endif
            </div>
        @endif
    </div>

    <div class="card-footer">
        <a href="{{ route('proxy-hosts.index') }}" class="btn btn-secondary" wire:navigate>Cancelar</a>
        <button type="submit" class="btn">
            <span wire:loading.remove wire:target="save">{{ $submitLabel }}</span>
            <span wire:loading wire:target="save">Salvando…</span>
        </button>
    </div>
</form>
