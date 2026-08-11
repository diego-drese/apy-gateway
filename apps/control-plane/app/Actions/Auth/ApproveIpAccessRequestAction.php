<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AuditLogAction;
use App\Enums\IpAccessRequestStatus;
use App\Models\AuditLog;
use App\Models\IpAccessRequest;
use App\Models\IpAllowlistEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ApproveIpAccessRequestAction
{
    /**
     * `$approver` is null on the public, unauthenticated email-link click (Fase 2's original
     * flow — the token itself is the proof, there's no logged-in actor) and set when approved
     * from the authenticated web UI (Fase 8) — `approved_by`/`audit_logs.user_id` reflect
     * whichever is true instead of always being null.
     */
    public function handle(IpAccessRequest $ipAccessRequest, string $approverIp, ?User $approver = null): IpAllowlistEntry
    {
        if ($ipAccessRequest->status !== IpAccessRequestStatus::Pending) {
            abort(410, 'Link inválido ou já utilizado.');
        }

        if ($ipAccessRequest->expires_at !== null && $ipAccessRequest->expires_at->isPast()) {
            $ipAccessRequest->update(['status' => IpAccessRequestStatus::Expired]);

            abort(410, 'Link expirado.');
        }

        return DB::transaction(function () use ($ipAccessRequest, $approverIp, $approver) {
            $ipAccessRequest->update([
                'status' => IpAccessRequestStatus::Approved,
                'approved_at' => now(),
            ]);

            $allowlistEntry = IpAllowlistEntry::query()->updateOrCreate(
                ['ip_address' => $ipAccessRequest->ip_address],
                [
                    'label' => $ipAccessRequest->requested_email,
                    'approved_by' => $approver?->id,
                    'approved_at' => now(),
                    'expires_at' => null,
                ],
            );

            AuditLog::query()->create([
                'user_id' => $approver?->id,
                'action' => AuditLogAction::IpAccessRequestApproved->value,
                'subject_type' => 'ip_allowlist_entry',
                'subject_id' => $allowlistEntry->id,
                'ip_address' => $approverIp,
                'metadata' => [
                    'requested_email' => $ipAccessRequest->requested_email,
                    'requester_ip' => $ipAccessRequest->ip_address,
                ],
            ]);

            return $allowlistEntry;
        });
    }
}
