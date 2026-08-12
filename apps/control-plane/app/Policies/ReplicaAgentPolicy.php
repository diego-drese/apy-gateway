<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReplicaAgent;
use App\Models\User;

/**
 * Same as SslCertificatePolicy: no role/permission column on `users` (single admin per
 * installation, no multi-tenancy — SPEC.md §3) — any authenticated user may view replicas. Rows
 * are system-managed (SyncReplicaMetricsFromHeartbeatsAction), never user-managed, so there's no
 * `view`/`create`/`update`/`delete` — only `GET /api/replicas` (index) exists.
 */
class ReplicaAgentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }
}
