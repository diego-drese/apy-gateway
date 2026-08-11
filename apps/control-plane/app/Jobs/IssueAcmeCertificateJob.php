<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Certificate\MarkCertificateIssuanceFailedAction;
use App\Actions\Certificate\PerformAcmeCertificateIssuanceAction;
use App\Enums\SslCertificateStatus;
use App\Models\SslCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Thin orchestration only (CLAUDE.md) — the ACME protocol itself lives entirely in
 * PerformAcmeCertificateIssuanceAction. Reused verbatim for both first issuance and renewal
 * (RFC 8555 renewal is just issuing again for the same certificate row).
 */
class IssueAcmeCertificateJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(public readonly int $sslCertificateId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->sslCertificateId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(PerformAcmeCertificateIssuanceAction $action): void
    {
        $certificate = SslCertificate::query()->findOrFail($this->sslCertificateId);

        $action->handle($certificate);
    }

    /**
     * Safety net: guarantees a certificate can never get silently stuck at `pending` after every
     * retry attempt is exhausted, even if `PerformAcmeCertificateIssuanceAction`'s own catch
     * block somehow didn't run.
     */
    public function failed(?Throwable $exception): void
    {
        $certificate = SslCertificate::query()->find($this->sslCertificateId);
        if ($certificate === null || $certificate->status === SslCertificateStatus::Error) {
            return;
        }

        app(MarkCertificateIssuanceFailedAction::class)->handle(
            $certificate,
            $exception?->getMessage() ?? 'Unknown error during ACME issuance.',
        );
    }
}
