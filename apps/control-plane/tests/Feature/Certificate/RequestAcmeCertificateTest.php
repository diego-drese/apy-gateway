<?php

declare(strict_types=1);

namespace Tests\Feature\Certificate;

use App\Enums\SslCertificateStatus;
use App\Enums\SslCertificateType;
use App\Jobs\IssueAcmeCertificateJob;
use App\Models\IpAllowlistEntry;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RequestAcmeCertificateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAllowlistedUser(): User
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_requesting_a_certificate_for_an_existing_proxy_host_creates_a_pending_certificate_and_dispatches_the_job(): void
    {
        Queue::fake();
        $this->actingAsAllowlistedUser();
        ProxyHost::factory()->create(['domain' => 'example.com', 'enabled' => true]);

        $response = $this->postJson('/api/certificates/request-acme', [
            'domain_names' => ['example.com'],
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.type', 'acme');
        $response->assertJsonPath('data.provider', 'letsencrypt');
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonMissingPath('data.storage_path');

        // ProxyHostFactory auto-creates its own (unrelated) SslCertificate — scope to the ACME
        // one this request actually created.
        $certificate = SslCertificate::query()->where('primary_domain', 'example.com')->where('type', SslCertificateType::Acme)->sole();
        $this->assertSame(SslCertificateType::Acme, $certificate->type);
        $this->assertSame(SslCertificateStatus::Pending, $certificate->status);
        $this->assertSame(['example.com'], $certificate->domain_names);

        Queue::assertPushed(IssueAcmeCertificateJob::class, fn (IssueAcmeCertificateJob $job) => $job->sslCertificateId === $certificate->id);
    }

    public function test_domain_without_an_active_proxy_host_is_rejected(): void
    {
        Queue::fake();
        $this->actingAsAllowlistedUser();

        $response = $this->postJson('/api/certificates/request-acme', [
            'domain_names' => ['no-proxy-host.example.com'],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, SslCertificate::query()->where('type', SslCertificateType::Acme)->count());
        Queue::assertNothingPushed();
    }

    public function test_domain_with_a_disabled_proxy_host_is_rejected(): void
    {
        Queue::fake();
        $this->actingAsAllowlistedUser();
        ProxyHost::factory()->create(['domain' => 'disabled.example.com', 'enabled' => false]);

        $this->postJson('/api/certificates/request-acme', [
            'domain_names' => ['disabled.example.com'],
        ])->assertStatus(422);

        $this->assertSame(0, SslCertificate::query()->where('primary_domain', 'disabled.example.com')->count());
    }

    public function test_a_second_request_for_the_same_pending_domain_reuses_the_existing_certificate(): void
    {
        Queue::fake();
        $this->actingAsAllowlistedUser();
        ProxyHost::factory()->create(['domain' => 'example.com', 'enabled' => true]);

        $first = $this->postJson('/api/certificates/request-acme', ['domain_names' => ['example.com']]);
        $second = $this->postJson('/api/certificates/request-acme', ['domain_names' => ['example.com']]);

        $this->assertSame(1, SslCertificate::query()->where('primary_domain', 'example.com')->where('type', SslCertificateType::Acme)->count());
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        Queue::assertPushed(IssueAcmeCertificateJob::class, 1);
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);

        $this->postJson('/api/certificates/request-acme', ['domain_names' => ['example.com']])
            ->assertStatus(401);
    }

    public function test_requests_from_a_non_allowlisted_ip_are_rejected_even_when_authenticated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/certificates/request-acme', ['domain_names' => ['example.com']])
            ->assertStatus(403);
    }
}
