<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ReplicaAgentStatus;
use App\Enums\SslCertificateStatus;
use App\Models\ProxyHost;
use App\Models\ReplicaAgent;
use App\Models\SslCertificate;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Landing page after login (NPM-style): one count per section, each linking to it. Every number
 * is a single aggregate query — no rows are loaded.
 */
#[Layout('layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render()
    {
        $this->authorize('viewAny', ProxyHost::class);

        return view('livewire.dashboard', [
            'proxyHostsTotal' => ProxyHost::query()->count(),
            'proxyHostsEnabled' => ProxyHost::query()->where('enabled', true)->count(),
            'certificatesValid' => SslCertificate::query()->where('status', SslCertificateStatus::Valid)->count(),
            'certificatesNeedingAttention' => SslCertificate::query()
                ->whereIn('status', [SslCertificateStatus::Expiring, SslCertificateStatus::Expired, SslCertificateStatus::Error])
                ->count(),
            'usersTotal' => User::query()->count(),
            'replicasOnline' => ReplicaAgent::query()->where('status', ReplicaAgentStatus::Online)->count(),
            'replicasTotal' => ReplicaAgent::query()->count(),
        ]);
    }
}
