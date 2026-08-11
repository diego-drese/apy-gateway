<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IpAccessRequest;
use App\Models\User;

/**
 * Same all-true pattern. Whether a specific request is still pending/not expired is a state
 * check, not an authorization check — it stays inside ApproveIpAccessRequestAction (the
 * existing abort(410, ...) guards), matching the same who-vs-what-state split as UserPolicy.
 */
class IpAccessRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function approve(User $user, IpAccessRequest $ipAccessRequest): bool
    {
        return true;
    }
}
