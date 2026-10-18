<div>
    <div class="page-header">
        <div>
            <h1 class="page-title">Editar usuário</h1>
        </div>
    </div>

    <form wire:submit="save" class="card form-card">
        <div class="card-body">
            <div class="field">
                <label class="label" for="name">Nome</label>
                <input id="name" type="text" class="input" wire:model="name" autofocus>
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label class="label" for="email">E-mail</label>
                <input id="email" type="email" class="input" wire:model="email">
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="card-footer">
            <a href="{{ route('users.index') }}" class="btn btn-secondary" wire:navigate>Cancelar</a>
            <button type="submit" class="btn">Salvar alterações</button>
        </div>
    </form>
</div>
