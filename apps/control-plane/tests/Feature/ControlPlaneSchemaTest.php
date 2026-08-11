<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AcmeChallengeStatus;
use App\Enums\DomainEventType;
use App\Enums\ForwardScheme;
use App\Enums\IpAccessRequestStatus;
use App\Enums\ReplicaAgentStatus;
use App\Models\AcmeChallenge;
use App\Models\AuditLog;
use App\Models\DomainEvent;
use App\Models\IpAccessRequest;
use App\Models\IpAllowlistEntry;
use App\Models\ProxyHost;
use App\Models\ReplicaAgent;
use App\Models\SslCertificate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlPlaneSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_proxy_host_relations_and_casts_resolve(): void
    {
        $user = User::factory()->create();
        $certificate = SslCertificate::factory()->create();

        $proxyHost = ProxyHost::factory()
            ->for($certificate, 'sslCertificate')
            ->create(['created_by' => $user->id]);

        $this->assertTrue($proxyHost->sslCertificate->is($certificate));
        $this->assertTrue($proxyHost->createdBy->is($user));
        $this->assertInstanceOf(ForwardScheme::class, $proxyHost->forward_scheme);
        $this->assertIsArray($proxyHost->custom_config ?? []);
    }

    public function test_proxy_host_domain_is_unique(): void
    {
        ProxyHost::factory()->create(['domain' => 'duplicate.example.com']);

        $this->expectException(QueryException::class);

        ProxyHost::factory()->create(['domain' => 'duplicate.example.com']);
    }

    public function test_ip_allowlist_entry_resolves_approver(): void
    {
        $admin = User::factory()->create();

        $entry = IpAllowlistEntry::factory()->create(['approved_by' => $admin->id]);

        $this->assertTrue($entry->approvedBy->is($admin));
    }

    public function test_ip_access_request_casts_status_and_hides_token(): void
    {
        $request = IpAccessRequest::factory()->create();

        $this->assertSame(IpAccessRequestStatus::Pending, $request->status);
        $this->assertArrayNotHasKey('token', $request->toArray());
    }

    public function test_replica_agent_casts_status(): void
    {
        $agent = ReplicaAgent::factory()->create();

        $this->assertSame(ReplicaAgentStatus::Online, $agent->status);
    }

    public function test_domain_event_resolves_polymorphic_subject_via_morph_map(): void
    {
        $proxyHost = ProxyHost::factory()->create();

        $event = DomainEvent::factory()
            ->for($proxyHost, 'subject')
            ->create(['type' => DomainEventType::ProxyHostCreated]);

        $this->assertTrue($event->subject->is($proxyHost));
        $this->assertSame('proxy_host', $event->getRawOriginal('subject_type'));
        $this->assertNull($event->updated_at);
    }

    public function test_audit_log_resolves_user_and_polymorphic_subject(): void
    {
        $user = User::factory()->create();

        $log = AuditLog::factory()
            ->for($user, 'subject')
            ->create(['user_id' => $user->id]);

        $this->assertTrue($log->user->is($user));
        $this->assertTrue($log->subject->is($user));
        $this->assertNull($log->updated_at);
    }

    public function test_acme_challenge_resolves_certificate_and_casts_status(): void
    {
        $certificate = SslCertificate::factory()->create();

        $challenge = AcmeChallenge::factory()
            ->for($certificate, 'sslCertificate')
            ->create();

        $this->assertTrue($challenge->sslCertificate->is($certificate));
        $this->assertSame(AcmeChallengeStatus::Pending, $challenge->status);
    }

    public function test_acme_challenge_token_is_unique(): void
    {
        AcmeChallenge::factory()->create(['token' => 'duplicate-token']);

        $this->expectException(QueryException::class);

        AcmeChallenge::factory()->create(['token' => 'duplicate-token']);
    }

    public function test_acme_challenge_is_deleted_when_certificate_is_deleted(): void
    {
        $certificate = SslCertificate::factory()->create();
        $challenge = AcmeChallenge::factory()->for($certificate, 'sslCertificate')->create();

        $certificate->delete();

        $this->assertDatabaseMissing('acme_challenges', ['id' => $challenge->id]);
    }
}
