<?php

declare(strict_types=1);

namespace App\Support\Acme;

use Rogierw\RwAcme\Api;
use Rogierw\RwAcme\Interfaces\HttpClientInterface;

/**
 * Builds the ACME protocol client. `$httpClient` is constructor-injected (nullable) rather than
 * hardcoded so tests can bind a fake `HttpClientInterface` in the container — when nothing is
 * bound, this falls back to `PebbleCompatibleHttpClient` (wraps the library's real cURL-based
 * client), which is what talks to the real ACME server (Let's Encrypt or, in local verification,
 * Pebble — SPEC.md §11).
 */
class AcmeClientFactory
{
    public function __construct(private readonly ?HttpClientInterface $httpClient = null)
    {
    }

    public function make(): Api
    {
        return new Api(
            staging: false,
            localAccount: new MinioAcmeAccount(config('services.acme.account_key_storage_path')),
            httpClient: $this->httpClient ?? new PebbleCompatibleHttpClient(),
            baseUrl: config('services.acme.base_url'),
        );
    }
}
