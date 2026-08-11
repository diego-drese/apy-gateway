<div>
    <h1>Definir senha</h1>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    <form wire:submit="submit">
        <div class="field">
            <label>E-mail</label>
            <input type="email" wire:model="email">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label>Nova senha</label>
            <input type="password" wire:model="password">
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label>Confirmar senha</label>
            <input type="password" wire:model="password_confirmation">
        </div>

        <button type="submit" class="btn">Definir senha</button>
    </form>
</div>
