<?php

declare(strict_types=1);

namespace App\Support\Acme;

use Rogierw\RwAcme\Http\Client;
use Rogierw\RwAcme\Http\Response;
use Rogierw\RwAcme\Interfaces\HttpClientInterface;

/**
 * Wraps the ACME client library's real cURL-based `Client` to paper over a genuine gap found
 * empirically against Pebble: `AccountData::fromResponse()` reads `$body['createdAt']` with no
 * fallback (unlike the `agreement` field two lines below it, which does have one) — RFC 8555
 * doesn't require that field, and Pebble's account responses simply don't include it. Backfilling
 * it here is harmless against real Let's Encrypt too (which does send it), so this always runs —
 * not a test-only shim.
 */
class PebbleCompatibleHttpClient implements HttpClientInterface
{
    private readonly Client $inner;

    public function __construct(int $timeout = 10)
    {
        $this->inner = new Client($timeout);
    }

    public function head(string $url): Response
    {
        return $this->inner->head($url);
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $arguments
     */
    public function get(string $url, array $headers = [], array $arguments = [], int $maxRedirects = 0): Response
    {
        return $this->inner->get($url, $headers, $arguments, $maxRedirects);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     */
    public function post(string $url, array $payload = [], array $headers = [], int $maxRedirects = 0): Response
    {
        return $this->backfillCreatedAt($this->inner->post($url, $payload, $headers, $maxRedirects));
    }

    private function backfillCreatedAt(Response $response): Response
    {
        $body = $response->getBody();

        // Scoped to account-object-shaped bodies (has `status`) so this never touches order/
        // challenge/finalize responses that legitimately have no `createdAt` field at all.
        if (! is_array($body) || array_key_exists('createdAt', $body) || ! array_key_exists('status', $body)) {
            return $response;
        }

        $body['createdAt'] = now()->toISOString();

        return new Response($response->getHeaders(), $response->getRequestedUrl(), $response->getHttpResponseCode(), $body);
    }
}
