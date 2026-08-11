<?php

declare(strict_types=1);

namespace App\Actions\Certificate;

use App\Actions\Events\RecordDomainEventAction;
use App\Enums\AcmeChallengeStatus;
use App\Enums\DomainEventType;
use App\Enums\SslCertificateStatus;
use App\Models\AcmeChallenge;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use App\Support\Acme\AcmeClientFactory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Rogierw\RwAcme\Api;
use Rogierw\RwAcme\DTO\AccountData;
use Rogierw\RwAcme\DTO\DomainValidationData;
use Rogierw\RwAcme\DTO\OrderData;
use Rogierw\RwAcme\Enums\AuthorizationChallengeEnum;
use Rogierw\RwAcme\Exceptions\LetsEncryptClientException;
use Rogierw\RwAcme\Support\Base64;
use Rogierw\RwAcme\Support\KeyId;
use RuntimeException;
use Throwable;

/**
 * Orchestrates one full ACME (RFC 8555) HTTP-01 issuance/renewal round-trip for a single
 * `SslCertificate`. Deliberately one class covering every protocol step (account → order →
 * challenges → validation → finalize → download), matching the existing precedent set by
 * `UploadCertificateAction` (validate + store + record, all in one class) rather than splitting
 * each step into its own micro-Action — nothing here is reused by any caller other than this
 * orchestrator (renewal reuses the whole thing, not individual steps).
 */
class PerformAcmeCertificateIssuanceAction
{
    public function __construct(
        private readonly AcmeClientFactory $acmeClientFactory,
        private readonly RecordDomainEventAction $recordDomainEvent,
        private readonly MarkCertificateIssuanceFailedAction $markFailed,
    ) {
    }

    public function handle(SslCertificate $certificate): void
    {
        $api = $this->acmeClientFactory->make();

        try {
            $account = $this->resolveAccount($api);

            $order = $api->order()->new($account, $certificate->domain_names);

            $challenges = $api->domainValidation()->status($order);
            $this->publishChallenges($certificate, $api, $challenges);

            sleep((int) config('services.acme.challenge_propagation_delay_seconds'));

            foreach ($challenges as $domainValidationData) {
                $this->triggerHttpChallenge($api, $account, $domainValidationData);
            }

            if (! $api->domainValidation()->allChallengesPassed($order)) {
                throw new RuntimeException("ACME domain validation failed for certificate {$certificate->id}.");
            }

            AcmeChallenge::query()
                ->where('ssl_certificate_id', $certificate->id)
                ->update(['status' => AcmeChallengeStatus::Valid]);

            $order = $this->refreshOrder($api, $order);

            [$privateKeyPem, $csrPem] = $this->generateCsr($certificate->domain_names);

            if (! $api->order()->finalize($order, $csrPem)) {
                throw new RuntimeException("ACME order finalization failed for certificate {$certificate->id}.");
            }

            $order = $this->pollUntilCertificateReady($api, $order);

            $bundle = $api->certificate()->getBundle($order);

            $this->storeIssuedCertificate($certificate, $bundle->fullchain, $privateKeyPem);
            $this->attachToProxyHosts($certificate);
        } catch (Throwable $e) {
            $this->markFailed->handle($certificate, $e->getMessage());

            throw $e;
        }
    }

    /**
     * The local account key can exist in MinIO without the ACME server actually knowing about it
     * yet — e.g. a prior attempt generated+persisted the key but failed before the registration
     * POST completed. In that case `account()->get()` (`onlyReturnExisting`) genuinely errors, so
     * fall back to `create()`: safe and idempotent per RFC 8555 §7.3 — submitting `newAccount`
     * with a key that's already registered just returns the existing account instead of erroring.
     */
    private function resolveAccount(Api $api): AccountData
    {
        if (! $api->account()->exists()) {
            return $api->account()->create();
        }

        try {
            return $api->account()->get();
        } catch (LetsEncryptClientException) {
            return $api->account()->create();
        }
    }

    /**
     * @param \Rogierw\RwAcme\DTO\DomainValidationData[] $challenges
     */
    private function publishChallenges(SslCertificate $certificate, Api $api, array $challenges): void
    {
        $authorizations = $api->domainValidation()->getValidationData($challenges, AuthorizationChallengeEnum::HTTP);

        AcmeChallenge::query()->where('ssl_certificate_id', $certificate->id)->delete();

        foreach ($authorizations as $authorization) {
            AcmeChallenge::query()->create([
                'ssl_certificate_id' => $certificate->id,
                'domain' => $authorization['identifier'],
                'token' => $authorization['filename'],
                'key_authorization' => $authorization['content'],
                'status' => AcmeChallengeStatus::Pending,
                // HTTP-01 authorizations are short-lived; comfortably longer than the
                // propagation-delay + poll budget below without being a long-lived stale row.
                'expires_at' => now()->addHour(),
            ]);
        }

        $this->recordDomainEvent->handle(DomainEventType::AcmeChallengeReady, $certificate, $certificate->version, null);
    }

    /**
     * Deliberately does NOT call `$api->domainValidation()->start()` — that method sends the
     * legacy `keyAuthorization` field in the challenge-response POST body. RFC 8555 §7.5.1
     * requires an empty body (`{}`); real Let's Encrypt (Boulder) silently ignores the extra
     * legacy field, but Pebble correctly rejects it as malformed (confirmed empirically against
     * a real Pebble instance — not assumed from reading the spec). The library's own
     * `KeyId::generate()` JWS helper can't even produce a literal `"{}"` payload (empty/falsy
     * payloads always encode to `""`), so there's no way to get a spec-compliant request through
     * the library's public API — this replicates its exact JWS-signing logic by hand, using only
     * the library's own public `Base64`/`nonce()`/`localAccount()` primitives, never touching
     * vendor internals. `start()`'s `localTest` pre-flight check is also skipped entirely — it
     * would have the control-plane container dial the domain directly, duplicating what the ACME
     * server is about to do and not fitting this project's topology (control-plane never makes
     * outbound calls to proxied domains — SPEC.md §6.0/§9.4).
     */
    private function triggerHttpChallenge(Api $api, AccountData $account, DomainValidationData $domainValidation): void
    {
        $challengeUrl = $domainValidation->file['url'];
        $nonce = $api->nonce()->getNew();
        $privateKey = openssl_pkey_get_private($api->localAccount()->getPrivateKey());

        $protected64 = Base64::urlSafeEncode(json_encode([
            'alg' => 'RS256',
            'kid' => $account->url,
            'nonce' => $nonce,
            'url' => $challengeUrl,
        ]));
        $payload64 = Base64::urlSafeEncode('{}');

        openssl_sign("{$protected64}.{$payload64}", $signature, $privateKey, 'SHA256');

        $api->getHttpClient()->post($challengeUrl, [
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => Base64::urlSafeEncode($signature),
        ]);
    }

    private function pollUntilCertificateReady(Api $api, OrderData $order): OrderData
    {
        $maxAttempts = (int) config('services.acme.acme_poll_max_attempts');
        $intervalSeconds = (int) config('services.acme.acme_poll_interval_seconds');

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $order = $this->refreshOrder($api, $order);

            if ($order->isValid() && $order->certificateUrl !== null) {
                return $order;
            }

            if ($order->isInvalid()) {
                throw new RuntimeException("ACME order {$order->id} became invalid while waiting for finalization.");
            }

            sleep($intervalSeconds);
        }

        throw new RuntimeException("ACME order {$order->id} did not finalize within the configured timeout.");
    }

    /**
     * Deliberately does NOT call `$api->order()->get($order->id)` — `Order::get()` derives the
     * per-order status URL via `Directory::getOrder()`, which does
     * `str_replace('new-order', 'order', $newOrderUrl)`. That's a Let's Encrypt/Boulder-specific
     * URL naming convention, not part of RFC 8555 — Pebble deliberately uses unrelated path names
     * (`/order-plz`, not `/new-order`), so the string-replace matches nothing and the synthesized
     * URL 404s (confirmed empirically: "Order cannot be found" against a real Pebble instance).
     * `OrderData` already carries the real, correct order URL (extracted from the order creation
     * response's `Location` header), sidestepping the broken synthesis entirely.
     *
     * Also deliberately signs a POST-as-GET (RFC 8555 §6.3) rather than issuing a plain GET —
     * confirmed empirically that Pebble rejects a plain GET to an order URL with 405, correctly
     * enforcing the final spec (early ACME drafts allowed plain GET; RFC 8555 requires POST-as-GET
     * for every resource but the directory/newNonce). `KeyId::generate()` with a null payload
     * already produces the correct empty-string POST-as-GET body — no vendor workaround needed
     * here, just calling the library's own public JWS helper directly instead of through
     * `Endpoint::createKeyId()` (protected, inaccessible outside the library's own classes).
     */
    private function refreshOrder(Api $api, OrderData $order): OrderData
    {
        $nonce = $api->nonce()->getNew();
        $signedPayload = KeyId::generate(
            $api->localAccount()->getPrivateKey(),
            $order->accountUrl,
            $order->url,
            $nonce,
        );

        $response = $api->getHttpClient()->post($order->url, $signedPayload);

        if ($response->getHttpResponseCode() >= 400) {
            throw new RuntimeException("Refreshing ACME order {$order->id} failed with HTTP {$response->getHttpResponseCode()}.");
        }

        return OrderData::fromResponse($response, $order->accountUrl);
    }

    private function storeIssuedCertificate(SslCertificate $certificate, string $fullchainPem, string $privateKeyPem): void
    {
        // openssl_x509_parse() only reads the first PEM block in a multi-certificate string —
        // CertificateBundleData::fromResponse() always puts the leaf certificate first in
        // `fullchain`, so this correctly reflects the leaf's validity window, not the chain's.
        $parsed = openssl_x509_parse($fullchainPem);
        $issuedAt = Carbon::createFromTimestamp($parsed['validFrom_time_t']);
        $expiresAt = Carbon::createFromTimestamp($parsed['validTo_time_t']);

        $storagePath = 'certificates/'.Str::uuid();
        $sseOptions = config('filesystems.disks.minio.sse_enabled')
            ? ['ServerSideEncryption' => 'AES256']
            : [];

        Storage::disk('minio')->put("{$storagePath}.crt", $fullchainPem, $sseOptions);
        Storage::disk('minio')->put("{$storagePath}.key", $privateKeyPem, $sseOptions);

        $isRenewal = $certificate->storage_path !== null;

        $certificate->update([
            'status' => SslCertificateStatus::Valid,
            'storage_path' => $storagePath,
            'content_hash' => hash('sha256', $fullchainPem),
            'version' => $isRenewal ? $certificate->version + 1 : $certificate->version,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'last_renewal_attempt_at' => now(),
        ]);

        $this->recordDomainEvent->handle(
            $isRenewal ? DomainEventType::CertificateRenewed : DomainEventType::CertificateIssued,
            $certificate,
            $certificate->version,
            null,
        );
    }

    /**
     * Closes the loop automatically (confirmed product decision — SPEC.md §11): once a
     * certificate becomes valid, every matching active proxy host gets it attached and reloaded
     * without a separate manual `PUT /api/proxy-hosts/{id}` step. Reuses agent code already
     * shipped since Fase 5/7 (proxy_host.updated → cert download → render) with zero Rust changes.
     */
    private function attachToProxyHosts(SslCertificate $certificate): void
    {
        ProxyHost::query()
            ->whereIn('domain', $certificate->domain_names)
            ->where('enabled', true)
            ->get()
            ->each(function (ProxyHost $proxyHost) use ($certificate): void {
                $proxyHost->update([
                    'ssl_certificate_id' => $certificate->id,
                    'version' => $proxyHost->version + 1,
                ]);

                $this->recordDomainEvent->handle(DomainEventType::ProxyHostUpdated, $proxyHost, $proxyHost->version, null);
            });
    }

    /**
     * @param array<int, string> $domainNames
     * @return array{0: string, 1: string} [privateKeyPem, csrPem]
     */
    private function generateCsr(array $domainNames): array
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($privateKey, $privateKeyPem);

        $sanConfigPath = tempnam(sys_get_temp_dir(), 'acme-csr-san-');
        $sanList = implode(',', array_map(fn (string $domain) => "DNS:{$domain}", $domainNames));
        file_put_contents($sanConfigPath, "[req]\ndistinguished_name=req\n[san]\nsubjectAltName={$sanList}\n");

        $csr = openssl_csr_new(
            ['commonName' => $domainNames[0]],
            $privateKey,
            ['digest_alg' => 'sha256', 'req_extensions' => 'san', 'config' => $sanConfigPath],
        );
        openssl_csr_export($csr, $csrPem);

        unlink($sanConfigPath);

        return [$privateKeyPem, $csrPem];
    }
}
