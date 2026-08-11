<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Same as ProxyHostPolicy: no role/permission column on `users` (single admin per
 * installation, no multi-tenancy — SPEC.md §3) — any authenticated user may manage users.
 * "Is this specific action currently valid" checks (self-delete, last-user) live in
 * DeleteUserAction, not here — the Policy answers "who", not "what state".
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, User $target): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, User $target): bool
    {
        return true;
    }

    public function delete(User $user, User $target): bool
    {
        return true;
    }
}
