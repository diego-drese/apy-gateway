<div class="card card-narrow">
    <h1>Enviar Certificado</h1>

    <form wire:submit="save">
        <div class="field">
            <label>Certificado (PEM)</label>
            <textarea wire:model="certificate" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
            @error('certificate') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label>Chave privada (PEM)</label>
            <textarea wire:model="private_key" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
            @error('private_key') <p class="error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn">Enviar</button>
        <a href="{{ route('certificates.index') }}" class="btn btn-secondary" wire:navigate>Cancelar</a>
    </form>
</div>
