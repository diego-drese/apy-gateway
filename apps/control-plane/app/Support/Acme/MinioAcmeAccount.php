<?php

declare(strict_types=1);

namespace App\Support\Acme;

use Illuminate\Support\Facades\Storage;
use Rogierw\RwAcme\Exceptions\LetsEncryptClientException;
use Rogierw\RwAcme\Interfaces\AcmeAccountInterface;
use Rogierw\RwAcme\Support\CryptRSA;

/**
 * Backs the ACME client's account key with MinIO instead of the library's built-in
 * `LocalFileAccount` (local filesystem) — the control-plane container is stateless/replaceable,
 * so the key needs to live somewhere that survives a container recreation, same as certificates
 * themselves (SPEC.md §11). Only the private key is persisted; the public key is always derived
 * from it (RSA public material is recoverable from the private key, so there's nothing to keep
 * in sync between two stored copies).
 */
class MinioAcmeAccount implements AcmeAccountInterface
{
    public function __construct(private readonly string $storagePath)
    {
    }

    public function getPrivateKey(): string
    {
        if (! $this->exists()) {
            throw new LetsEncryptClientException('ACME account key does not exist yet.');
        }

        return Storage::disk('minio')->get($this->storagePath);
    }

    public function getPublicKey(): string
    {
        $privateKey = openssl_pkey_get_private($this->getPrivateKey());
        if ($privateKey === false) {
            throw new LetsEncryptClientException('Stored ACME account key could not be parsed.');
        }

        $details = openssl_pkey_get_details($privateKey);

        return $details['key'];
    }

    public function exists(): bool
    {
        return Storage::disk('minio')->exists($this->storagePath);
    }

    public function generateNewKeys(string $keyType = 'RSA'): bool
    {
        if ($keyType !== 'RSA') {
            throw new LetsEncryptClientException('Only RSA ACME account keys are supported.');
        }

        $keys = CryptRSA::generate();

        return Storage::disk('minio')->put($this->storagePath, $keys['privateKey']);
    }
}
