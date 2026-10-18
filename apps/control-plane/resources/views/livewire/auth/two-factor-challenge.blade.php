<div>
    <h1 class="guest-title">Verificação em duas etapas</h1>
    <p class="guest-subtitle">Enviamos um código de 6 dígitos para o seu e-mail.</p>

    <form wire:submit="verify">
        <div class="field">
            <label class="label" for="code">Código</label>
            <input id="code" type="text" class="input mono" inputmode="numeric" maxlength="6" autocomplete="one-time-code" wire:model="code" autofocus>
            @error('code') <p class="error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-block">Verificar</button>
    </form>
</div>
