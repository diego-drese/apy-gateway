<div class="card card-narrow">
    <h1>Novo Usuário</h1>

    <p class="status">Um e-mail com um link para definir a senha será enviado ao usuário.</p>

    <form wire:submit="save">
        <div class="field">
            <label>Nome</label>
            <input type="text" wire:model="name" autofocus>
            @error('name') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label>E-mail</label>
            <input type="email" wire:model="email">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn">Criar</button>
        <a href="{{ route('users.index') }}" class="btn btn-secondary" wire:navigate>Cancelar</a>
    </form>
</div>
