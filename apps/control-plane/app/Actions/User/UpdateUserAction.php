<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\AuditLogAction;
use App\Models\AuditLog;
use App\Models\User;

class UpdateUserAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function handle(User $user, array $data, User $actor, string $ipAddress): User
    {
        // Only name/email — password changes only ever happen via the reset-link flow
        // (CreateUserAction/SetPassword), never a directly-assigned value here.
        $user->update($data);

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => AuditLogAction::UserUpdated->value,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'ip_address' => $ipAddress,
        ]);

        return $user;
    }
}
