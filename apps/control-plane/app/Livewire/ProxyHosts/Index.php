<?php

declare(strict_types=1);

namespace App\Livewire\ProxyHosts;

use App\Actions\ProxyHost\DeleteProxyHostAction;
use App\Models\ProxyHost;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

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
            'proxyHosts' => ProxyHost::query()->with('sslCertificate')->latest()->paginate(15),
        ]);
    }
}
