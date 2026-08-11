<?php

declare(strict_types=1);

namespace App\Actions\Certificate;

use App\Enums\SslCertificateType;
use App\Models\SslCertificate;
use Illuminate\Database\Eloquent\Collection;

/**
 * Split out from the renewal Command because "which certificates are due" is a business rule
 * (CLAUDE.md: business logic never lives in Commands), independently testable from the
 * orchestration of dispatching jobs for them.
 */
class FindCertificatesDueForRenewalAction
{
    /**
     * @return Collection<int, SslCertificate>
     */
    public function handle(int $thresholdDays): Collection
    {
        return SslCertificate::query()
            ->where('type', SslCertificateType::Acme)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($thresholdDays))
            ->get();
    }
}
