<?php

declare(strict_types=1);

namespace App\Actions\Certificate;

use App\Actions\Events\RecordDomainEventAction;
use App\Enums\DomainEventType;
use App\Enums\SslCertificateProvider;
use App\Enums\SslCertificateStatus;
use App\Enums\SslCertificateType;
use App\Models\SslCertificate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadCertificateAction
{
    public function __construct(
        private readonly RecordDomainEventAction $recordDomainEvent,
    ) {
    }

    public function handle(string $certificatePem, string $privateKeyPem, User $actor): SslCertificate
    {
        $parsed = @openssl_x509_parse($certificatePem);
        if ($parsed === false) {
            throw ValidationException::withMessages(['certificate' => ['Certificado inválido.']]);
        }

        $privateKey = @openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw ValidationException::withMessages(['private_key' => ['Chave privada inválida.']]);
        }

        if (! openssl_x509_check_private_key($certificatePem, $privateKey)) {
            throw ValidationException::withMessages(['private_key' => ['A chave privada não corresponde ao certificado.']]);
        }

        $issuedAt = isset($parsed['validFrom_time_t']) ? Carbon::createFromTimestamp($parsed['validFrom_time_t']) : null;
        $expiresAt = isset($parsed['validTo_time_t']) ? Carbon::createFromTimestamp($parsed['validTo_time_t']) : null;

        if ($expiresAt === null || $expiresAt->isPast()) {
            throw ValidationException::withMessages(['certificate' => ['Certificado expirado.']]);
        }
        if ($issuedAt !== null && $issuedAt->isFuture()) {
            throw ValidationException::withMessages(['certificate' => ['Certificado ainda não é válido.']]);
        }

        $primaryDomain = $parsed['subject']['CN'] ?? null;
        if (! $primaryDomain) {
            throw ValidationException::withMessages(['certificate' => ['Certificado sem domínio (CN) no subject.']]);
        }

        $domainNames = $this->extractDomainNames($parsed, $primaryDomain);

        $storagePath = 'certificates/'.Str::uuid();

        $sseOptions = config('filesystems.disks.minio.sse_enabled')
            ? ['ServerSideEncryption' => 'AES256']
            : [];

        Storage::disk('minio')->put("{$storagePath}.crt", $certificatePem, $sseOptions);
        Storage::disk('minio')->put("{$storagePath}.key", $privateKeyPem, $sseOptions);

        $certificate = SslCertificate::query()->create([
            'type' => SslCertificateType::Uploaded,
            'primary_domain' => $primaryDomain,
            'domain_names' => $domainNames,
            'provider' => SslCertificateProvider::Manual,
            'status' => SslCertificateStatus::Valid,
            'storage_path' => $storagePath,
            'content_hash' => hash('sha256', $certificatePem),
            'version' => 1,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ]);

        $this->recordDomainEvent->handle(DomainEventType::CertificateIssued, $certificate, 1, $actor->id);

        return $certificate;
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<int, string>
     */
    private function extractDomainNames(array $parsed, string $primaryDomain): array
    {
        $san = $parsed['extensions']['subjectAltName'] ?? null;
        if (! $san) {
            return [$primaryDomain];
        }

        $domains = collect(explode(',', $san))
            ->map(fn (string $entry) => trim($entry))
            ->filter(fn (string $entry) => str_starts_with($entry, 'DNS:'))
            ->map(fn (string $entry) => substr($entry, strlen('DNS:')))
            ->values()
            ->all();

        return $domains !== [] ? $domains : [$primaryDomain];
    }
}
