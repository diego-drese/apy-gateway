<div>
    <div class="page-header">
        <div>
            <h1 class="page-title">Enviar certificado</h1>
            <p class="page-subtitle">Certificado e chave privada em formato PEM, armazenados no MinIO.</p>
        </div>
    </div>

    <form wire:submit="save" class="card form-card">
        <div class="card-body">
            <div class="field">
                <label class="label" for="certificate">Certificado (PEM)</label>
                <textarea id="certificate" class="textarea" wire:model="certificate" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                <p class="hint">Inclua a cadeia intermediária logo após o certificado, se houver.</p>
                @error('certificate') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label class="label" for="private_key">Chave privada (PEM)</label>
                <textarea id="private_key" class="textarea" wire:model="private_key" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
                @error('private_key') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="card-footer">
            <a href="{{ route('certificates.index') }}" class="btn btn-secondary" wire:navigate>Cancelar</a>
            <button type="submit" class="btn"><x-icon name="upload" class="icon-sm" /> Enviar</button>
        </div>
    </form>
</div>
