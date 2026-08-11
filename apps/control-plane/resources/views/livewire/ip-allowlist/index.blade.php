<div>
    <div class="card">
        <h1>Solicitações pendentes</h1>

        @if ($pendingRequests->isEmpty())
            <p class="empty">Nenhuma solicitação pendente.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>IP</th>
                        <th>E-mail</th>
                        <th>Solicitado em</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pendingRequests as $request)
                        <tr wire:key="ip-request-{{ $request->id }}">
                            <td>{{ $request->ip_address }}</td>
                            <td>{{ $request->requested_email }}</td>
                            <td>{{ $request->created_at?->format('d/m/Y H:i') }}</td>
                            <td>
                                <button type="button" class="btn" wire:click="approve({{ $request->id }})" wire:confirm="Aprovar este IP?">Aprovar</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card" style="margin-top: 1.5rem;">
        <h1>IPs liberados</h1>

        <form wire:submit="addEntry" style="display: flex; gap: 1rem; align-items: flex-start; margin-bottom: 1.5rem;">
            <div class="field" style="flex: 1; margin-bottom: 0;">
                <label>IP</label>
                <input type="text" wire:model="ip_address" placeholder="203.0.113.10">
                @error('ip_address') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field" style="flex: 1; margin-bottom: 0;">
                <label>Rótulo (opcional)</label>
                <input type="text" wire:model="label">
                @error('label') <p class="error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn" style="margin-top: 1.5rem;">Adicionar</button>
        </form>

        @if ($entries->isEmpty())
            <p class="empty">Nenhum IP liberado.</p>
        @else
            <table>
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
                            <td>{{ $entry->ip_address }}</td>
                            <td>{{ $entry->label ?? '—' }}</td>
                            <td>{{ $entry->approved_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td>{{ $entry->expires_at?->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                            <td>
                                <button type="button" class="btn btn-danger" wire:click="revoke({{ $entry->id }})" wire:confirm="Revogar este IP?">Revogar</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{ $entries->links() }}
        @endif
    </div>
</div>
