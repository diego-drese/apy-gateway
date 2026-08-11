<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SslCertificateProvider;
use App\Enums\SslCertificateStatus;
use App\Enums\SslCertificateType;
use App\Jobs\IssueAcmeCertificateJob;
use App\Models\SslCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckExpiringCertificatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function acmeCertificate(string $domain, ?\DateTimeInterface $expiresAt, SslCertificateStatus $status = SslCertificateStatus::Valid): SslCertificate
    {
        return SslCertificate::query()->create([
            'type' => SslCertificateType::Acme,
            'primary_domain' => $domain,
            'domain_names' => [$domain],
            'provider' => SslCertificateProvider::LetsEncrypt,
            'status' => $status,
            'version' => 1,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_dispatches_renewal_for_certificates_expiring_within_the_threshold(): void
    {
        Queue::fake();
        config(['services.acme.renewal_threshold_days' => 30]);

        $expiringSoon = $this->acmeCertificate('expiring.example.com', now()->addDays(10));
        $notExpiringSoon = $this->acmeCertificate('healthy.example.com', now()->addDays(90));
        $noExpiry = $this->acmeCertificate('pending.example.com', null, SslCertificateStatus::Pending);

        $this->artisan('certificates:check-expiring')->assertSuccessful();

        Queue::assertPushed(IssueAcmeCertificateJob::class, 1);
        Queue::assertPushed(IssueAcmeCertificateJob::class, fn (IssueAcmeCertificateJob $job) => $job->sslCertificateId === $expiringSoon->id);
    }

    public function test_ignores_uploaded_certificates(): void
    {
        Queue::fake();

        SslCertificate::query()->create([
            'type' => SslCertificateType::Uploaded,
            'primary_domain' => 'manual.example.com',
            'domain_names' => ['manual.example.com'],
            'provider' => SslCertificateProvider::Manual,
            'status' => SslCertificateStatus::Valid,
            'version' => 1,
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('certificates:check-expiring')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_retries_certificates_already_in_error_status_within_the_threshold(): void
    {
        Queue::fake();

        $errored = $this->acmeCertificate('errored.example.com', now()->addDays(5), SslCertificateStatus::Error);

        $this->artisan('certificates:check-expiring')->assertSuccessful();

        Queue::assertPushed(IssueAcmeCertificateJob::class, fn (IssueAcmeCertificateJob $job) => $job->sslCertificateId === $errored->id);
    }
}
