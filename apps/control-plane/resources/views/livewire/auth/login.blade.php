<div>
    <h1>Entrar</h1>

    <form wire:submit="login">
        <div class="field">
            <label>E-mail</label>
            <input type="email" wire:model="email" autofocus>
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label>Senha</label>
            <input type="password" wire:model="password">
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn">Entrar</button>
    </form>
</div>
