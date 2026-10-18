<?php

declare(strict_types=1);

namespace App\Livewire\ProxyHosts;

use App\Actions\ProxyHost\DeleteProxyHostAction;
use App\Models\ProxyHost;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Proxy Hosts')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function delete(int $proxyHostId, DeleteProxyHostAction $action): void
    {
        $proxyHost = ProxyHost::query()->findOrFail($proxyHostId);

        $this->authorize('delete', $proxyHost);

        $action->handle($proxyHost, auth()->user());
    }

    public function render()
    {
        $this->authorize('viewAny', ProxyHost::class);

        return view('livewire.proxy-hosts.index', [
            'proxyHosts' => ProxyHost::query()
                ->with('sslCertificate')
                ->when(trim($this->search) !== '', fn ($query) => $query->where('domain', 'like', '%'.trim($this->search).'%'))
                ->latest()
                ->paginate(15),
        ]);
    }
}
