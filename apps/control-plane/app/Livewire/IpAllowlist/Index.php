<?php

declare(strict_types=1);

namespace App\Livewire\IpAllowlist;

use App\Actions\Auth\ApproveIpAccessRequestAction;
use App\Actions\IpAllowlist\CreateIpAllowlistEntryAction;
use App\Actions\IpAllowlist\DeleteIpAllowlistEntryAction;
use App\Enums\IpAccessRequestStatus;
use App\Models\IpAccessRequest;
use App\Models\IpAllowlistEntry;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $ip_address = '';
    public string $label = '';

    public function mount(): void
    {
        $this->authorize('viewAny', IpAllowlistEntry::class);
        $this->authorize('viewAny', IpAccessRequest::class);
    }

    public function addEntry(CreateIpAllowlistEntryAction $action): void
    {
        $this->authorize('create', IpAllowlistEntry::class);

        $data = $this->validate([
            'ip_address' => ['required', 'ip', 'max:45'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $action->handle($data['ip_address'], $data['label'] ?: null, null, auth()->user(), request()->ip());

        $this->reset(['ip_address', 'label']);
    }

    public function revoke(int $entryId, DeleteIpAllowlistEntryAction $action): void
    {
        $entry = IpAllowlistEntry::query()->findOrFail($entryId);

        $this->authorize('delete', $entry);

        $action->handle($entry, auth()->user(), request()->ip());
    }

    public function approve(int $ipAccessRequestId, ApproveIpAccessRequestAction $action): void
    {
        $ipAccessRequest = IpAccessRequest::query()->findOrFail($ipAccessRequestId);

        $this->authorize('approve', $ipAccessRequest);

        $action->handle($ipAccessRequest, request()->ip(), auth()->user());
    }

    public function render()
    {
        return view('livewire.ip-allowlist.index', [
            'entries' => IpAllowlistEntry::query()->latest()->paginate(15),
            'pendingRequests' => IpAccessRequest::query()
                ->where('status', IpAccessRequestStatus::Pending)
                ->latest()
                ->get(),
        ]);
    }
}
