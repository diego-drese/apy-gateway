<?php

declare(strict_types=1);

namespace App\Actions\IpAllowlist;

use App\Enums\AuditLogAction;
use App\Models\AuditLog;
use App\Models\IpAllowlistEntry;
use App\Models\User;

class DeleteIpAllowlistEntryAction
{
    /**
     * Deliberately no self-lockout guard here (unlike DeleteUserAction's two guards) — losing
     * IP access is recoverable via the existing email-approval escape hatch (RequestIpAccessAction
     * since Fase 2); losing the last user account has no recovery path at all.
     */
    public function handle(IpAllowlistEntry $entry, User $actor, string $actorIp): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => AuditLogAction::IpAllowlistEntryDeleted->value,
            'subject_type' => 'ip_allowlist_entry',
            'subject_id' => $entry->id,
            'ip_address' => $actorIp,
            'metadata' => ['ip_address' => $entry->ip_address],
        ]);

        $entry->delete();
    }
}
