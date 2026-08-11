<div class="card">
    <div class="toolbar">
        <h1>Usuários</h1>
        <a href="{{ route('users.create') }}" class="btn" wire:navigate>Novo</a>
    </div>

    @error('user') <p class="error">{{ $message }}</p> @enderror

    @if ($users->isEmpty())
        <p class="empty">Nenhum usuário cadastrado.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>2FA</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr wire:key="user-{{ $user->id }}">
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->two_factor_enabled ? 'Sim' : 'Não' }}</td>
                        <td>
                            <a href="{{ route('users.edit', $user) }}" wire:navigate>Editar</a>
                            <button type="button" class="btn btn-danger" wire:click="delete({{ $user->id }})" wire:confirm="Apagar este usuário?">Apagar</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $users->links() }}
    @endif
</div>
