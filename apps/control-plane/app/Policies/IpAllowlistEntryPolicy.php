<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IpAllowlistEntry;
use App\Models\User;

/**
 * Same all-true pattern as ProxyHostPolicy. No `update`/`view` abilities — SPEC.md §5 only
 * describes adding, listing, and revoking allowlist entries, never editing an existing one.
 */
class IpAllowlistEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, IpAllowlistEntry $entry): bool
    {
        return true;
    }
}
