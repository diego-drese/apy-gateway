<div>
    <h1 class="guest-title">Entrar</h1>
    <p class="guest-subtitle">Acesse o painel do gateway.</p>

    <form wire:submit="login">
        <div class="field">
            <label class="label" for="email">E-mail</label>
            <input id="email" type="email" class="input" wire:model="email" autocomplete="username" autofocus>
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="label" for="password">Senha</label>
            <input id="password" type="password" class="input" wire:model="password" autocomplete="current-password">
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-block">
            <span wire:loading.remove wire:target="login">Entrar</span>
            <span wire:loading wire:target="login">Entrando…</span>
        </button>
    </form>
</div>
