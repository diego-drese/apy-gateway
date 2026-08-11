<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SslCertificate;
use App\Models\User;

/**
 * Same as ProxyHostPolicy: no role/permission column on `users` (single admin per
 * installation, no multi-tenancy — SPEC.md §3) — any authenticated user may manage certificates.
 */
class SslCertificatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SslCertificate $certificate): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
