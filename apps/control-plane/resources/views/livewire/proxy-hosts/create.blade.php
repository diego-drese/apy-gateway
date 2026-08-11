<div class="card card-narrow">
    <h1>Novo Proxy Host</h1>

    <form wire:submit="save">
        <div class="field">
            <label>Domínio</label>
            <input type="text" wire:model="domain" autofocus>
            @error('domain') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label>Esquema de destino</label>
            <select wire:model="forward_scheme">
                <option value="http">http</option>
                <option value="https">https</option>
            </select>
            @error('forward_scheme') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label>Host de destino</label>
            <input type="text" wire:model="forward_host">
            @error('forward_host') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label>Porta de destino</label>
            <input type="number" wire:model="forward_port">
            @error('forward_port') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label>Certificado SSL</label>
            <select wire:model="ssl_certificate_id">
                <option value="">Nenhum</option>
                @foreach ($certificates as $certificate)
                    <option value="{{ $certificate->id }}">{{ $certificate->primary_domain }}</option>
                @endforeach
            </select>
            @error('ssl_certificate_id') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label><input type="checkbox" wire:model="websockets_enabled" style="width:auto"> Websockets habilitado</label>
        </div>

        <div class="field">
            <label><input type="checkbox" wire:model="enabled" style="width:auto"> Habilitado</label>
        </div>

        <button type="submit" class="btn">Criar</button>
        <a href="{{ route('proxy-hosts.index') }}" class="btn btn-secondary" wire:navigate>Cancelar</a>
    </form>
</div>
