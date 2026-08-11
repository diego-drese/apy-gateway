<?php

declare(strict_types=1);

namespace Tests\Feature\IpAllowlist;

use App\Enums\IpAccessRequestStatus;
use App\Models\AuditLog;
use App\Models\IpAccessRequest;
use App\Models\IpAllowlistEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IpAccessRequestApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAllowlistedUser(): User
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_lists_only_pending_requests(): void
    {
        $this->actingAsAllowlistedUser();
        IpAccessRequest::factory()->create(['status' => IpAccessRequestStatus::Pending]);
        IpAccessRequest::factory()->create(['status' => IpAccessRequestStatus::Approved]);

        $response = $this->getJson('/api/ip-access-requests');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_authenticated_approval_records_the_approver_unlike_the_public_token_flow(): void
    {
        $approver = $this->actingAsAllowlistedUser();
        $ipAccessRequest = IpAccessRequest::factory()->create([
            'ip_address' => '203.0.113.20',
            'status' => IpAccessRequestStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->postJson("/api/ip-access-requests/{$ipAccessRequest->id}/approve");

        // Laravel auto-sets 201 for a JsonResource wrapping a freshly-created model
        // (Eloquent's `wasRecentlyCreated`) — this IP had no prior allowlist entry.
        $response->assertCreated();

        $entry = IpAllowlistEntry::query()->where('ip_address', '203.0.113.20')->sole();
        $this->assertSame($approver->id, $entry->approved_by);

        $log = AuditLog::query()->where('action', 'ip_access_request.approved')->sole();
        $this->assertSame($approver->id, $log->user_id);
    }
}
