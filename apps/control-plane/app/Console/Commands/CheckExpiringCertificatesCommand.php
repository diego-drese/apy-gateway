<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Certificate\FindCertificatesDueForRenewalAction;
use App\Jobs\IssueAcmeCertificateJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SPEC.md §11: scheduled renewal, ~30 days before expiry by default (services.acme.
 * renewal_threshold_days). Thin orchestration only — "which certificates are due" is
 * FindCertificatesDueForRenewalAction's job, "how to renew one" is the same
 * IssueAcmeCertificateJob first issuance already uses (RFC 8555 renewal is just issuing again).
 */
class CheckExpiringCertificatesCommand extends Command
{
    protected $signature = 'certificates:check-expiring';

    protected $description = 'Dispatch renewal jobs for ACME certificates nearing expiry';

    public function handle(FindCertificatesDueForRenewalAction $findDueForRenewal): int
    {
        $thresholdDays = (int) config('services.acme.renewal_threshold_days');
        $certificates = $findDueForRenewal->handle($thresholdDays);

        foreach ($certificates as $certificate) {
            IssueAcmeCertificateJob::dispatch($certificate->id);
        }

        Log::info('Checked for expiring ACME certificates', [
            'threshold_days' => $thresholdDays,
            'dispatched' => $certificates->count(),
        ]);

        $this->info("Dispatched renewal for {$certificates->count()} certificate(s) expiring within {$thresholdDays} days.");

        return self::SUCCESS;
    }
}
