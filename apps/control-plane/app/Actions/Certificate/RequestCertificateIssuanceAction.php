<?php

declare(strict_types=1);

namespace App\Actions\Certificate;

use App\Enums\SslCertificateProvider;
use App\Enums\SslCertificateStatus;
use App\Enums\SslCertificateType;
use App\Jobs\IssueAcmeCertificateJob;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RequestCertificateIssuanceAction
{
    /**
     * @param array<int, string> $domainNames
     */
    public function handle(array $domainNames, User $actor): SslCertificate
    {
        // $actor isn't used yet (ssl_certificates has no created_by column and SPEC.md only
        // requires audit_logs for login/IP/2FA/user CRUD, not certificate issuance) — kept in the
        // signature to match UploadCertificateAction::handle()'s shape for a predictable,
        // consistent call interface across every certificate-creation entry point.
        $primaryDomain = $domainNames[0];

        // Every domain must already have an active proxy_host — otherwise there is no rendered
        // `:80` server block for the agent's acme-challenge location to live in, and the failure
        // would only surface minutes later at the ACME server instead of immediately here.
        $missingDomains = collect($domainNames)
            ->reject(fn (string $domain) => ProxyHost::query()->where('domain', $domain)->where('enabled', true)->exists())
            ->values();

        if ($missingDomains->isNotEmpty()) {
            throw ValidationException::withMessages([
                'domain_names' => [
                    'Os domínios a seguir precisam existir como proxy host ativo antes da emissão: '
                    .$missingDomains->implode(', ').'.',
                ],
            ]);
        }

        $existingPending = SslCertificate::query()
            ->where('primary_domain', $primaryDomain)
            ->where('type', SslCertificateType::Acme)
            ->where('status', SslCertificateStatus::Pending)
            ->first();

        if ($existingPending !== null) {
            return $existingPending;
        }

        $certificate = SslCertificate::query()->create([
            'type' => SslCertificateType::Acme,
            'primary_domain' => $primaryDomain,
            'domain_names' => $domainNames,
            'provider' => SslCertificateProvider::LetsEncrypt,
            'status' => SslCertificateStatus::Pending,
            'version' => 1,
        ]);

        IssueAcmeCertificateJob::dispatch($certificate->id);

        return $certificate;
    }
}
