<?php

declare(strict_types=1);

namespace App\Actions\Certificate;

use App\Enums\AcmeChallengeStatus;
use App\Enums\SslCertificateStatus;
use App\Models\AcmeChallenge;
use App\Models\SslCertificate;
use Illuminate\Support\Facades\Log;

/**
 * Called from two places (PerformAcmeCertificateIssuanceAction's catch block, and
 * IssueAcmeCertificateJob::failed() as a safety net) — genuine reuse, unlike the individual ACME
 * protocol steps, which is why this is its own class rather than inlined.
 */
class MarkCertificateIssuanceFailedAction
{
    public function handle(SslCertificate $certificate, string $reason): void
    {
        Log::warning('ACME certificate issuance failed', [
            'certificate_id' => $certificate->id,
            'primary_domain' => $certificate->primary_domain,
            'reason' => $reason,
        ]);

        $certificate->update([
            'status' => SslCertificateStatus::Error,
            'last_renewal_attempt_at' => now(),
        ]);

        AcmeChallenge::query()
            ->where('ssl_certificate_id', $certificate->id)
            ->where('status', AcmeChallengeStatus::Pending)
            ->update(['status' => AcmeChallengeStatus::Invalid]);
    }
}
