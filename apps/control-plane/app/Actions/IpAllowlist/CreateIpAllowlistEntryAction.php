<?php

declare(strict_types=1);

namespace App\Actions\IpAllowlist;

use App\Enums\AuditLogAction;
use App\Models\AuditLog;
use App\Models\IpAllowlistEntry;
use App\Models\User;
use Carbon\Carbon;

class CreateIpAllowlistEntryAction
{
    public function handle(
        string $ipAddress,
        ?string $label,
        ?Carbon $expiresAt,
        User $actor,
        string $actorIp,
    ): IpAllowlistEntry {
        // Keyed by ip_address, same dedup pattern as ApproveIpAccessRequestAction — there's no
        // unique DB constraint on this column, so this in-app upsert is the only guard against
        // duplicate rows for the same IP.
        $entry = IpAllowlistEntry::query()->updateOrCreate(
            ['ip_address' => $ipAddress],
            [
                'label' => $label,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'expires_at' => $expiresAt,
            ],
        );

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => AuditLogAction::IpAllowlistEntryCreated->value,
            'subject_type' => 'ip_allowlist_entry',
            'subject_id' => $entry->id,
            'ip_address' => $actorIp,
            'metadata' => ['ip_address' => $ipAddress],
        ]);

        return $entry;
    }
}
