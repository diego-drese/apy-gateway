<div>
    <h1 class="guest-title">Definir senha</h1>
    <p class="guest-subtitle">Escolha a senha de acesso ao painel.</p>

    @if (session('status'))
        <div class="alert alert-success"><x-icon name="check" /> <span>{{ session('status') }}</span></div>
    @endif

    <form wire:submit="submit">
        <div class="field">
            <label class="label" for="email">E-mail</label>
            <input id="email" type="email" class="input" wire:model="email" autocomplete="username">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="label" for="password">Nova senha</label>
            <input id="password" type="password" class="input" wire:model="password" autocomplete="new-password">
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="label" for="password_confirmation">Confirmar senha</label>
            <input id="password_confirmation" type="password" class="input" wire:model="password_confirmation" autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-block">Definir senha</button>
    </form>
</div>
