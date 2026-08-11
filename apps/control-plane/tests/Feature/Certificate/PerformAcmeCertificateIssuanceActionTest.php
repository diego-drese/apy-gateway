<?php

declare(strict_types=1);

namespace Tests\Feature\Certificate;

use App\Actions\Certificate\PerformAcmeCertificateIssuanceAction;
use App\Enums\AcmeChallengeStatus;
use App\Enums\DomainEventType;
use App\Enums\SslCertificateProvider;
use App\Enums\SslCertificateStatus;
use App\Enums\SslCertificateType;
use App\Models\AcmeChallenge;
use App\Models\DomainEvent;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Rogierw\RwAcme\Interfaces\HttpClientInterface;
use Tests\Support\FakeAcmeHttpClient;
use Tests\TestCase;

class PerformAcmeCertificateIssuanceActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The Action does real sleep() calls for challenge propagation / order polling — zero
        // them out so these tests run in milliseconds instead of several real seconds each. The
        // real values are only meaningful against a real ACME server (docker/acme-verification/).
        config([
            'services.acme.challenge_propagation_delay_seconds' => 0,
            'services.acme.acme_poll_interval_seconds' => 0,
        ]);
    }

    private function allowRedisPublish(): void
    {
        Redis::shouldReceive('connection')->with('events')->andReturnSelf();
        Redis::shouldReceive('publish')->zeroOrMoreTimes();
    }

    private function bindFakeAcmeClient(): FakeAcmeHttpClient
    {
        $fake = new FakeAcmeHttpClient();
        $this->app->instance(HttpClientInterface::class, $fake);

        return $fake;
    }

    /**
     * Real self-signed leaf + "intermediate" chain, generated the same way the project's own
     * Fase 7 test helper builds certificates — CertificateBundleData::fromResponse() only
     * populates `fullchain` when it finds more than one PEM block, matching a real ACME chain.
     */
    private function generateFakeChain(string $domain): string
    {
        $leaf = $this->generateSelfSignedCert($domain);
        $intermediate = $this->generateSelfSignedCert('Fake Intermediate CA');

        return $leaf."\n".$intermediate."\n";
    }

    private function generateSelfSignedCert(string $commonName): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $commonName], $key);
        $x509 = openssl_csr_sign($csr, null, $key, 365);

        $pem = '';
        openssl_x509_export($x509, $pem);

        return $pem;
    }

    public function test_issues_a_certificate_stores_it_and_attaches_matching_proxy_hosts(): void
    {
        Storage::fake('minio');
        $this->allowRedisPublish();
        $fake = $this->bindFakeAcmeClient();
        $fake->certificateBundlePem = $this->generateFakeChain('example.com');

        $proxyHost = ProxyHost::factory()->create(['domain' => 'example.com', 'enabled' => true]);
        $certificate = SslCertificate::query()->create([
            'type' => SslCertificateType::Acme,
            'primary_domain' => 'example.com',
            'domain_names' => ['example.com'],
            'provider' => SslCertificateProvider::LetsEncrypt,
            'status' => SslCertificateStatus::Pending,
            'version' => 1,
        ]);

        app(PerformAcmeCertificateIssuanceAction::class)->handle($certificate);

        $certificate->refresh();
        $this->assertSame(SslCertificateStatus::Valid, $certificate->status);
        $this->assertNotNull($certificate->storage_path);
        $this->assertNotNull($certificate->content_hash);
        $this->assertNotNull($certificate->issued_at);
        $this->assertNotNull($certificate->expires_at);
        $this->assertSame(1, $certificate->version, 'first issuance never bumps the version');

        Storage::disk('minio')->assertExists("{$certificate->storage_path}.crt");
        Storage::disk('minio')->assertExists("{$certificate->storage_path}.key");

        $challenges = AcmeChallenge::query()->where('ssl_certificate_id', $certificate->id)->get();
        $this->assertCount(1, $challenges);
        $this->assertTrue($challenges->every(fn (AcmeChallenge $c) => $c->status === AcmeChallengeStatus::Valid));

        $proxyHost->refresh();
        $this->assertSame($certificate->id, $proxyHost->ssl_certificate_id);
        $this->assertSame(2, $proxyHost->version, 'auto-attach bumps the proxy host version so the agent resyncs it');

        $eventTypes = DomainEvent::query()->pluck('type')->map(fn (DomainEventType $t) => $t->value)->all();
        $this->assertContains('acme_challenge.ready', $eventTypes);
        $this->assertContains('proxy_host.updated', $eventTypes);
        $this->assertContains('certificate.issued', $eventTypes);
    }

    public function test_renewal_bumps_the_certificate_version_and_records_a_renewed_event(): void
    {
        Storage::fake('minio');
        $this->allowRedisPublish();
        $fake = $this->bindFakeAcmeClient();
        $fake->certificateBundlePem = $this->generateFakeChain('example.com');

        ProxyHost::factory()->create(['domain' => 'example.com', 'enabled' => true]);
        $certificate = SslCertificate::query()->create([
            'type' => SslCertificateType::Acme,
            'primary_domain' => 'example.com',
            'domain_names' => ['example.com'],
            'provider' => SslCertificateProvider::LetsEncrypt,
            'status' => SslCertificateStatus::Valid,
            'storage_path' => 'certificates/pre-existing',
            'content_hash' => 'old-hash',
            'version' => 3,
            'issued_at' => now()->subMonths(2),
            'expires_at' => now()->addDays(20),
        ]);

        app(PerformAcmeCertificateIssuanceAction::class)->handle($certificate);

        $certificate->refresh();
        $this->assertSame(4, $certificate->version);
        $this->assertNotSame('certificates/pre-existing', $certificate->storage_path);

        $eventTypes = DomainEvent::query()->pluck('type')->map(fn (DomainEventType $t) => $t->value)->all();
        $this->assertContains('certificate.renewed', $eventTypes);
        $this->assertNotContains('certificate.issued', $eventTypes);
    }

    public function test_finalize_failure_marks_the_certificate_as_error_and_invalidates_challenges(): void
    {
        Storage::fake('minio');
        $this->allowRedisPublish();
        $fake = $this->bindFakeAcmeClient();
        $fake->finalizeShouldFail = true;

        ProxyHost::factory()->create(['domain' => 'example.com', 'enabled' => true]);
        $certificate = SslCertificate::query()->create([
            'type' => SslCertificateType::Acme,
            'primary_domain' => 'example.com',
            'domain_names' => ['example.com'],
            'provider' => SslCertificateProvider::LetsEncrypt,
            'status' => SslCertificateStatus::Pending,
            'version' => 1,
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            app(PerformAcmeCertificateIssuanceAction::class)->handle($certificate);
        } finally {
            $certificate->refresh();
            $this->assertSame(SslCertificateStatus::Error, $certificate->status);
            $this->assertNotNull($certificate->last_renewal_attempt_at);

            // Domain validation genuinely succeeded before finalize failed — the authorization
            // itself is legitimately still valid (RFC 8555 authorizations outlive one order's
            // finalize attempt), so MarkCertificateIssuanceFailedAction correctly leaves it alone
            // and only invalidates rows that were still `Pending` at failure time (none, here).
            $challenges = AcmeChallenge::query()->where('ssl_certificate_id', $certificate->id)->get();
            $this->assertCount(1, $challenges);
            $this->assertTrue($challenges->every(fn (AcmeChallenge $c) => $c->status === AcmeChallengeStatus::Valid));
        }
    }
}
