<div>
    <div class="page-header">
        <div>
            <h1 class="page-title">Acesso por IP</h1>
            <p class="page-subtitle">Só IPs desta lista chegam à tela de login e à API.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Solicitações pendentes <span class="count-pill">{{ $pendingRequests->count() }}</span></h2>
        </div>

        @if ($pendingRequests->isEmpty())
            <div class="empty-state">
                <x-icon name="check" />
                <div class="empty-state-title">Nenhuma solicitação pendente</div>
            </div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>IP</th>
                            <th>Solicitante</th>
                            <th>Solicitado em</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendingRequests as $request)
                            <tr wire:key="ip-request-{{ $request->id }}">
                                <td class="mono cell-primary">{{ $request->ip_address }}</td>
                                <td>{{ $request->requested_email }}</td>
                                <td>{{ $request->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="cell-actions">
                                    <button type="button" class="btn btn-sm" wire:click="approve({{ $request->id }})" wire:confirm="Aprovar o IP {{ $request->ip_address }}?">
                                        <x-icon name="check" class="icon-sm" /> Aprovar
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">IPs liberados <span class="count-pill">{{ $entries->total() }}</span></h2>
        </div>

        <div class="card-body card-section">
            <form wire:submit="addEntry" class="inline-form">
                <div class="field">
                    <label class="label" for="ip_address">IP</label>
                    <input id="ip_address" type="text" class="input" wire:model="ip_address" placeholder="203.0.113.10">
                    @error('ip_address') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="label" for="label">Rótulo (opcional)</label>
                    <input id="label" type="text" class="input" wire:model="label" placeholder="Escritório, VPN…">
                    @error('label') <p class="error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn"><x-icon name="plus" class="icon-sm" /> Adicionar</button>
            </form>
        </div>

        @if ($entries->isEmpty())
            <div class="empty-state">
                <x-icon name="lock" />
                <div class="empty-state-title">Nenhum IP liberado</div>
            </div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>IP</th>
                            <th>Rótulo</th>
                            <th>Aprovado em</th>
                            <th>Expira em</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr wire:key="ip-entry-{{ $entry->id }}">
                                <td class="mono cell-primary">{{ $entry->ip_address }}</td>
                                <td>{{ $entry->label ?? '—' }}</td>
                                <td>{{ $entry->approved_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>{{ $entry->expires_at?->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                                <td class="cell-actions">
                                    <button type="button" class="icon-btn icon-btn-danger" title="Revogar" aria-label="Revogar"
                                            wire:click="revoke({{ $entry->id }})"
                                            wire:confirm="Revogar o IP {{ $entry->ip_address }}?">
                                        <x-icon name="trash" class="icon-sm" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $entries->links('partials.pagination') }}
        @endif
    </div>
</div>
