<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\SslCertificateProvider;
use App\Enums\SslCertificateStatus;
use App\Enums\SslCertificateType;
use App\Jobs\IssueAcmeCertificateJob;
use App\Models\SslCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class IssueAcmeCertificateJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_hook_marks_the_certificate_as_error(): void
    {
        $certificate = SslCertificate::query()->create([
            'type' => SslCertificateType::Acme,
            'primary_domain' => 'example.com',
            'domain_names' => ['example.com'],
            'provider' => SslCertificateProvider::LetsEncrypt,
            'status' => SslCertificateStatus::Pending,
            'version' => 1,
        ]);

        (new IssueAcmeCertificateJob($certificate->id))->failed(new RuntimeException('simulated queue exhaustion'));

        $certificate->refresh();
        $this->assertSame(SslCertificateStatus::Error, $certificate->status);
        $this->assertNotNull($certificate->last_renewal_attempt_at);
    }

    public function test_failed_hook_is_a_no_op_when_certificate_was_already_marked_error(): void
    {
        $certificate = SslCertificate::query()->create([
            'type' => SslCertificateType::Acme,
            'primary_domain' => 'example.com',
            'domain_names' => ['example.com'],
            'provider' => SslCertificateProvider::LetsEncrypt,
            'status' => SslCertificateStatus::Error,
            'version' => 1,
            'last_renewal_attempt_at' => now()->subMinute(),
        ]);
        $attemptAt = $certificate->last_renewal_attempt_at;

        (new IssueAcmeCertificateJob($certificate->id))->failed(new RuntimeException('simulated'));

        $certificate->refresh();
        $this->assertTrue($certificate->last_renewal_attempt_at->equalTo($attemptAt));
    }

    public function test_failed_hook_is_a_no_op_when_certificate_no_longer_exists(): void
    {
        // Should not throw even though there's nothing to mark.
        (new IssueAcmeCertificateJob(999999))->failed(new RuntimeException('simulated'));

        $this->assertTrue(true);
    }
}
