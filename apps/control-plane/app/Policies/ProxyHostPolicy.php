<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProxyHost;
use App\Models\User;

/**
 * There is no role/permission column on `users` (single admin per installation, no
 * multi-tenancy — SPEC.md §3) — any authenticated user may manage every proxy host.
 */
class ProxyHostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProxyHost $proxyHost): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ProxyHost $proxyHost): bool
    {
        return true;
    }

    public function delete(User $user, ProxyHost $proxyHost): bool
    {
        return true;
    }
}
