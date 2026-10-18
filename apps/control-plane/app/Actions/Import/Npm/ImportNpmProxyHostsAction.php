<?php

declare(strict_types=1);

namespace App\Actions\Import\Npm;

use App\Actions\Certificate\UploadCertificateAction;
use App\Actions\ProxyHost\CreateProxyHostAction;
use App\Enums\ForwardScheme;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use App\Models\User;
use App\Rules\ForwardHost;
use App\Rules\Hostname;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Migrates proxy hosts from an Nginx Proxy Manager installation into apy-gateway.
 *
 * Inputs: the JSON array returned by NPM's `GET /api/nginx/proxy-hosts`, and a directory holding
 * NPM's certificate files as `npm-{certificate_id}/fullchain.pem` + `privkey.pem` (the layout of
 * both `/etc/letsencrypt/live` and `/data/custom_ssl` inside NPM's volumes).
 * Output: an NpmImportReport placing every domain in exactly one bucket.
 *
 * Mapping: NPM groups several domains in one host; apy-gateway is one domain per proxy host
 * (SPEC.md §5), so each domain becomes its own host with the same upstream. Existing certificates
 * are uploaded as-is (deduplicated by content hash), so the cutover needs no new issuance.
 *
 * Idempotent: domains that already exist are left untouched, so the import can be re-run after
 * fixing whatever was skipped.
 */
class ImportNpmProxyHostsAction
{
    /** @var array<int, int|null> NPM certificate id => apy-gateway ssl_certificates.id */
    private array $uploadedCertificates = [];

    public function __construct(
        private readonly CreateProxyHostAction $createProxyHost,
        private readonly UploadCertificateAction $uploadCertificate,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rawHosts
     */
    public function handle(
        array $rawHosts,
        string $certificatesDir,
        User $actor,
        bool $dryRun = false,
        bool $includeUnsupported = false,
    ): NpmImportReport {
        $startedAt = microtime(true);
        $report = new NpmImportReport();
        $this->uploadedCertificates = [];

        foreach ($rawHosts as $rawHost) {
            $this->importHost(NpmProxyHost::fromArray($rawHost), $certificatesDir, $actor, $dryRun, $includeUnsupported, $report);
        }

        Log::info('NPM proxy host import finished', [
            'dry_run' => $dryRun,
            'npm_hosts' => count($rawHosts),
            'created' => count($report->created),
            'already_existing' => count($report->alreadyExisting),
            'skipped' => count($report->skipped),
            'certificates_uploaded' => count($report->certificatesUploaded),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $report;
    }

    private function importHost(
        NpmProxyHost $host,
        string $certificatesDir,
        User $actor,
        bool $dryRun,
        bool $includeUnsupported,
        NpmImportReport $report,
    ): void {
        $blocking = $host->blockingFeatures();
        $certificateFiles = $host->certificateId !== null
            ? $this->certificateFiles($certificatesDir, $host->certificateId)
            : null;

        if ($host->certificateId !== null && $certificateFiles === null) {
            $blocking[] = "arquivos do certificado npm-{$host->certificateId} não encontrados em {$certificatesDir}";
        }

        foreach ($host->domainNames as $domain) {
            if (ProxyHost::query()->where('domain', $domain)->exists()) {
                $report->alreadyExisting[] = $domain;

                continue;
            }

            $invalid = $this->validationErrors($domain, $host);
            if ($invalid !== null) {
                $report->skipped[$domain] = $invalid;

                continue;
            }

            if ($blocking !== [] && ! $includeUnsupported) {
                $report->skipped[$domain] = 'recursos não suportados: '.implode('; ', $blocking);

                continue;
            }

            $notReproduced = [...$host->ignoredFeatures(), ...$blocking];
            if ($notReproduced !== []) {
                $report->warnings[$domain] = $notReproduced;
            }

            if ($dryRun) {
                $this->recordCreated($report, $domain, $host);

                continue;
            }

            try {
                $certificateId = $certificateFiles !== null
                    ? $this->resolveCertificate($host->certificateId, $certificateFiles, $actor, $report)
                    : null;
            } catch (ValidationException $e) {
                $report->skipped[$domain] = "certificado npm-{$host->certificateId} recusado: ".implode(' ', $e->validator->errors()->all());

                continue;
            }

            $this->createProxyHost->handle([
                'domain' => $domain,
                'forward_scheme' => $host->forwardScheme,
                'forward_host' => $host->forwardHost,
                'forward_port' => $host->forwardPort,
                'websockets_enabled' => $host->websocketsEnabled,
                'ssl_certificate_id' => $certificateId,
                'enabled' => $host->enabled,
            ], $actor);

            $this->recordCreated($report, $domain, $host);
        }
    }

    private function recordCreated(NpmImportReport $report, string $domain, NpmProxyHost $host): void
    {
        $report->created[] = $domain;

        if (! $host->enabled) {
            $report->createdDisabled[] = $domain;
        }
    }

    /**
     * External data is validated with the exact same rules as the API (never trusted, CLAUDE.md).
     */
    private function validationErrors(string $domain, NpmProxyHost $host): ?string
    {
        $validator = Validator::make([
            'domain' => $domain,
            'forward_scheme' => $host->forwardScheme,
            'forward_host' => $host->forwardHost,
            'forward_port' => $host->forwardPort,
        ], [
            'domain' => ['required', 'string', 'max:253', new Hostname()],
            'forward_scheme' => ['required', Rule::enum(ForwardScheme::class)],
            'forward_host' => ['required', 'string', 'max:253', new ForwardHost()],
            'forward_port' => ['required', 'integer', 'between:1,65535'],
        ]);

        return $validator->fails() ? implode(' ', $validator->errors()->all()) : null;
    }

    /**
     * @return array{certificate: string, private_key: string}|null
     */
    private function certificateFiles(string $certificatesDir, int $npmCertificateId): ?array
    {
        $dir = rtrim($certificatesDir, '/')."/npm-{$npmCertificateId}";
        $certificate = "{$dir}/fullchain.pem";
        $privateKey = "{$dir}/privkey.pem";

        if (! is_readable($certificate) || ! is_readable($privateKey)) {
            return null;
        }

        return [
            'certificate' => (string) file_get_contents($certificate),
            'private_key' => (string) file_get_contents($privateKey),
        ];
    }

    /**
     * One NPM certificate is usually shared by every domain of the host (SAN), so it is uploaded
     * once per run — and reused across runs when an identical certificate is already stored.
     *
     * @param array{certificate: string, private_key: string} $files
     */
    private function resolveCertificate(int $npmCertificateId, array $files, User $actor, NpmImportReport $report): int
    {
        if (isset($this->uploadedCertificates[$npmCertificateId])) {
            return $this->uploadedCertificates[$npmCertificateId];
        }

        $existing = SslCertificate::query()
            ->where('content_hash', hash('sha256', $files['certificate']))
            ->value('id');

        if ($existing === null) {
            $existing = $this->uploadCertificate->handle($files['certificate'], $files['private_key'], $actor)->id;
            $report->certificatesUploaded[] = $npmCertificateId;
        }

        return $this->uploadedCertificates[$npmCertificateId] = (int) $existing;
    }
}
