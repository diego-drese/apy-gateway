<div>
    <h1>Verificação em duas etapas</h1>
    <p>Enviamos um código de 6 dígitos para o seu e-mail.</p>

    <form wire:submit="verify">
        <div class="field">
            <label>Código</label>
            <input type="text" inputmode="numeric" maxlength="6" wire:model="code" autofocus>
            @error('code') <p class="error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn">Verificar</button>
    </form>
</div>
