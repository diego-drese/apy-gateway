<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\IpAccessRequestStatus;
use App\Mail\IpAccessRequestSubmittedMail;
use App\Models\AuditLog;
use App\Models\IpAccessRequest;
use App\Models\IpAllowlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class IpAccessRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_creates_pending_row_and_emails_the_admin(): void
    {
        Mail::fake();

        $response = $this->postJson('/auth/ip-requests', ['email' => 'requester@example.com']);

        $response->assertStatus(201);

        $ipAccessRequest = IpAccessRequest::query()->sole();
        $this->assertSame(IpAccessRequestStatus::Pending, $ipAccessRequest->status);
        $this->assertSame('requester@example.com', $ipAccessRequest->requested_email);
        $this->assertSame('127.0.0.1', $ipAccessRequest->ip_address);

        Mail::assertQueued(
            IpAccessRequestSubmittedMail::class,
            fn (IpAccessRequestSubmittedMail $mail) => $mail->hasTo(config('security.admin_notification_email'))
                && $mail->ipAccessRequest->is($ipAccessRequest),
        );
    }

    public function test_approving_creates_allowlist_entry_and_audit_log(): void
    {
        $ipAccessRequest = IpAccessRequest::factory()->create([
            'ip_address' => '203.0.113.10',
            'status' => IpAccessRequestStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->get(route('auth.ip-requests.approve', $ipAccessRequest->token));

        $response->assertOk();

        $ipAccessRequest->refresh();
        $this->assertSame(IpAccessRequestStatus::Approved, $ipAccessRequest->status);

        $entry = IpAllowlistEntry::query()->where('ip_address', '203.0.113.10')->sole();
        $this->assertNull($entry->approved_by);

        $log = AuditLog::query()->sole();
        $this->assertSame('ip_access_request.approved', $log->action);
        $this->assertSame('203.0.113.10', $log->metadata['requester_ip']);
    }

    public function test_reusing_an_approved_token_fails(): void
    {
        $ipAccessRequest = IpAccessRequest::factory()->create([
            'status' => IpAccessRequestStatus::Approved,
            'approved_at' => now(),
        ]);

        $this->get(route('auth.ip-requests.approve', $ipAccessRequest->token))
            ->assertStatus(410);
    }

    public function test_expired_token_fails(): void
    {
        $ipAccessRequest = IpAccessRequest::factory()->create([
            'status' => IpAccessRequestStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);

        $this->get(route('auth.ip-requests.approve', $ipAccessRequest->token))
            ->assertStatus(410);

        $this->assertSame(IpAccessRequestStatus::Expired, $ipAccessRequest->fresh()->status);
    }
}
