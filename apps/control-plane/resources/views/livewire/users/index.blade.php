<div>
    <div class="page-header">
        <div>
            <h1 class="page-title">Usuários</h1>
            <p class="page-subtitle">Quem pode acessar este painel.</p>
        </div>
        <a href="{{ route('users.create') }}" class="btn" wire:navigate>
            <x-icon name="plus" class="icon-sm" /> Novo usuário
        </a>
    </div>

    @error('user')
        <div class="alert alert-danger"><x-icon name="info" /> <span>{{ $message }}</span></div>
    @enderror

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Usuários <span class="count-pill">{{ $users->total() }}</span></h2>
        </div>

        @if ($users->isEmpty())
            <div class="empty-state">
                <x-icon name="users" />
                <div class="empty-state-title">Nenhum usuário</div>
            </div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Verificação em duas etapas</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr wire:key="user-{{ $user->id }}">
                                <td>
                                    <div class="user-cell">
                                        <span class="avatar">{{ Str::upper(Str::substr($user->name, 0, 1)) }}</span>
                                        <div>
                                            <div class="cell-primary">{{ $user->name }}</div>
                                            <div class="cell-secondary">{{ $user->email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    @if ($user->two_factor_enabled)
                                        <span class="badge badge-success">Ativa</span>
                                    @else
                                        <span class="badge">Inativa</span>
                                    @endif
                                </td>
                                <td class="cell-actions">
                                    <a href="{{ route('users.edit', $user) }}" class="icon-btn" title="Editar" aria-label="Editar" wire:navigate>
                                        <x-icon name="pencil" class="icon-sm" />
                                    </a>
                                    <button type="button" class="icon-btn icon-btn-danger" title="Apagar" aria-label="Apagar"
                                            wire:click="delete({{ $user->id }})"
                                            wire:confirm="Apagar o usuário {{ $user->email }}?">
                                        <x-icon name="trash" class="icon-sm" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $users->links('partials.pagination') }}
        @endif
    </div>
</div>
