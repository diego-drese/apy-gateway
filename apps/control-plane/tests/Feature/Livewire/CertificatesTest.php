<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Certificates\Index;
use App\Livewire\Certificates\Upload;
use App\Models\SslCertificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CertificatesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: string} [certificatePem, privateKeyPem]
     */
    private function generateCertificate(string $commonName): array
    {
        $key = openssl_pkey_new(['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $commonName], $key);
        $x509 = openssl_csr_sign($csr, null, $key, 365, [], 1);

        $certificatePem = '';
        openssl_x509_export($x509, $certificatePem);
        $privateKeyPem = '';
        openssl_pkey_export($key, $privateKeyPem);

        return [$certificatePem, $privateKeyPem];
    }

    public function test_index_renders_existing_certificates(): void
    {
        $user = User::factory()->create();
        $certificate = SslCertificate::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee($certificate->primary_domain);
    }

    public function test_upload_persists_a_valid_certificate(): void
    {
        Storage::fake('minio');
        Redis::shouldReceive('connection')->with('events')->andReturnSelf();
        Redis::shouldReceive('publish')->zeroOrMoreTimes();
        $user = User::factory()->create();

        [$certificatePem, $privateKeyPem] = $this->generateCertificate('livewire.test');

        Livewire::actingAs($user)
            ->test(Upload::class)
            ->set('certificate', $certificatePem)
            ->set('private_key', $privateKeyPem)
            ->call('save')
            ->assertRedirect(route('certificates.index'));

        $this->assertDatabaseHas('ssl_certificates', ['primary_domain' => 'livewire.test']);
    }
}
