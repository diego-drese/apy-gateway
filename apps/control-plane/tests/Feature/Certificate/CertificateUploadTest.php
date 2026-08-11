<?php

declare(strict_types=1);

namespace Tests\Feature\Certificate;

use App\Models\DomainEvent;
use App\Models\IpAllowlistEntry;
use App\Models\SslCertificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificateUploadTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAllowlistedUser(): User
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function allowRedisPublish(): void
    {
        Redis::shouldReceive('connection')->with('events')->andReturnSelf();
        Redis::shouldReceive('publish')->zeroOrMoreTimes();
    }

    /**
     * @return array{0: string, 1: string} [certificatePem, privateKeyPem]
     */
    private function generateCertificate(string $commonName, int $days = 365, ?string $sanDomain = null): array
    {
        $sanDomain ??= $commonName;
        $confPath = tempnam(sys_get_temp_dir(), 'san').'.cnf';
        file_put_contents($confPath, <<<CNF
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
[req_distinguished_name]
[v3_req]
subjectAltName = DNS:{$sanDomain},DNS:www.{$sanDomain}
CNF
        );

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'x509_extensions' => 'v3_req',
            'req_extensions' => 'v3_req',
            'config' => $confPath,
        ];

        $key = openssl_pkey_new($config);
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $config);
        $x509 = openssl_csr_sign($csr, null, $key, $days, $config, 1);

        $certificatePem = '';
        openssl_x509_export($x509, $certificatePem);
        $privateKeyPem = '';
        openssl_pkey_export($key, $privateKeyPem);

        unlink($confPath);

        return [$certificatePem, $privateKeyPem];
    }

    public function test_uploading_a_valid_certificate_creates_it_and_publishes_an_event(): void
    {
        Storage::fake('minio');
        $this->allowRedisPublish();
        $user = $this->actingAsAllowlistedUser();

        [$certificatePem, $privateKeyPem] = $this->generateCertificate('example.com');

        $response = $this->postJson('/api/certificates/upload', [
            'certificate' => $certificatePem,
            'private_key' => $privateKeyPem,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.primary_domain', 'example.com');
        $response->assertJsonPath('data.domain_names', ['example.com', 'www.example.com']);
        $response->assertJsonPath('data.provider', 'manual');
        $response->assertJsonPath('data.status', 'valid');
        $response->assertJsonMissingPath('data.storage_path');

        $certificate = SslCertificate::query()->sole();
        Storage::disk('minio')->assertExists("{$certificate->storage_path}.crt");
        Storage::disk('minio')->assertExists("{$certificate->storage_path}.key");

        $event = DomainEvent::query()->sole();
        $this->assertSame('certificate.issued', $event->type->value);
        $this->assertSame('ssl_certificate', $event->getRawOriginal('subject_type'));
        $this->assertSame($certificate->id, $event->subject_id);
        $this->assertSame($user->id, $event->created_by);
    }

    public function test_mismatched_private_key_is_rejected(): void
    {
        Storage::fake('minio');
        $this->actingAsAllowlistedUser();

        [$certificatePem] = $this->generateCertificate('example.com');
        [, $otherKeyPem] = $this->generateCertificate('other.example.com');

        $this->postJson('/api/certificates/upload', [
            'certificate' => $certificatePem,
            'private_key' => $otherKeyPem,
        ])->assertStatus(422);

        $this->assertDatabaseCount('ssl_certificates', 0);
    }

    public function test_expired_certificate_is_rejected(): void
    {
        Storage::fake('minio');
        $this->actingAsAllowlistedUser();

        // openssl_csr_sign() rejects negative `days`, so generate a short-lived certificate and
        // travel past its expiry instead of trying to sign an already-expired one.
        [$certificatePem, $privateKeyPem] = $this->generateCertificate('expired.example.com', days: 1);
        $this->travel(2)->days();

        $this->postJson('/api/certificates/upload', [
            'certificate' => $certificatePem,
            'private_key' => $privateKeyPem,
        ])->assertStatus(422);

        $this->assertDatabaseCount('ssl_certificates', 0);
    }

    public function test_malformed_pem_is_rejected(): void
    {
        $this->actingAsAllowlistedUser();

        $this->postJson('/api/certificates/upload', [
            'certificate' => "-----BEGIN CERTIFICATE-----\nnot a real certificate\n-----END CERTIFICATE-----",
            'private_key' => "-----BEGIN PRIVATE KEY-----\nnot a real key\n-----END PRIVATE KEY-----",
        ])->assertStatus(422);
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);

        $this->getJson('/api/certificates')->assertStatus(401);
    }

    public function test_requests_from_a_non_allowlisted_ip_are_rejected_even_when_authenticated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/certificates')->assertStatus(403);
    }
}
