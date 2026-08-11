<?php

declare(strict_types=1);

namespace Tests\Support;

use Rogierw\RwAcme\Http\Response;
use Rogierw\RwAcme\Interfaces\HttpClientInterface;

/**
 * Simulates a full RFC 8555 HTTP-01 conversation for `PerformAcmeCertificateIssuanceAction`
 * tests, without a real ACME server. Routes purely by URL substring/HTTP method rather than call
 * order/count, so it stays robust against the real client library's incidental extra calls (e.g.
 * it re-fetches `/directory` on every single request instead of caching it).
 *
 * The constructor signature is fixed by `HttpClientInterface` itself (`__construct(int $timeout =
 * 10)`), so fixtures are configured via public properties after construction, not the
 * constructor.
 */
class FakeAcmeHttpClient implements HttpClientInterface
{
    private const BASE = 'https://fake-acme.test';

    public string $primaryDomain = 'example.com';

    /** @var array<int, string> */
    public array $domainNames = ['example.com'];

    public string $certificateBundlePem = '';

    // Deliberately not a "challenge validation fails" flag: DomainValidation::allChallengesPassed()
    // retries up to 4 times with a real 5-second sleep() between attempts, which would make that
    // failure path ~15-20s per test. Failing at finalize instead exercises the same catch/rethrow
    // ->MarkCertificateIssuanceFailedAction path in milliseconds.
    public bool $finalizeShouldFail = false;

    private bool $accountCreated = false;

    private bool $orderFinalized = false;

    private int $nonceCounter = 0;

    public function __construct(int $timeout = 10)
    {
    }

    public function head(string $url): Response
    {
        return new Response(['replay-nonce' => 'test-nonce-'.(++$this->nonceCounter)], $url, 200, '');
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $arguments
     */
    public function get(string $url, array $headers = [], array $arguments = [], int $maxRedirects = 0): Response
    {
        if (str_contains($url, '/directory')) {
            return $this->directoryResponse($url);
        }

        throw new \RuntimeException("FakeAcmeHttpClient: unhandled GET {$url}");
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     */
    public function post(string $url, array $payload = [], array $headers = [], int $maxRedirects = 0): Response
    {
        if (str_contains($url, '/new-acct')) {
            return $this->accountResponse();
        }

        if (str_contains($url, '/new-order')) {
            return $this->newOrderResponse();
        }

        if (str_contains($url, '/http-01')) {
            return new Response([], $url, 200, []);
        }

        if (str_contains($url, '/authz/')) {
            return $this->authorizationResponse($url);
        }

        // Must be checked before the generic "/order/" branch below — the finalize URL
        // ("/order/1/finalize") also contains the substring "/order/".
        if (str_contains($url, '/finalize')) {
            if ($this->finalizeShouldFail) {
                return new Response([], $url, 400, ['type' => 'urn:ietf:params:acme:error:malformed', 'detail' => 'simulated finalize failure']);
            }

            $this->orderFinalized = true;

            return new Response([], $url, 200, ['certificate' => self::BASE.'/cert/1']);
        }

        if (str_contains($url, '/order/')) {
            return $this->orderResponse();
        }

        if (str_contains($url, '/cert/')) {
            return new Response([], $url, 200, $this->certificateBundlePem);
        }

        throw new \RuntimeException("FakeAcmeHttpClient: unhandled POST {$url}");
    }

    private function directoryResponse(string $url): Response
    {
        return new Response([], $url, 200, [
            'newNonce' => self::BASE.'/new-nonce',
            'newAccount' => self::BASE.'/new-acct',
            'newOrder' => self::BASE.'/new-order',
            'revokeCert' => self::BASE.'/revoke-cert',
        ]);
    }

    private function accountResponse(): Response
    {
        // Account::create() requires 201, Account::get() requires 200 — the first call this
        // fake ever sees to /new-acct is always the create() call, every subsequent one is a
        // get() re-fetch (Order::get() calls account()->get() internally on every poll).
        $code = $this->accountCreated ? 200 : 201;
        $this->accountCreated = true;

        return new Response(
            ['location' => self::BASE.'/acct/1'],
            self::BASE.'/new-acct',
            $code,
            ['status' => 'valid', 'key' => [], 'createdAt' => now()->toISOString()],
        );
    }

    private function newOrderResponse(): Response
    {
        return new Response(
            ['location' => self::BASE.'/order/1'],
            self::BASE.'/new-order',
            201,
            [
                'status' => 'pending',
                'expires' => now()->addHour()->toISOString(),
                'identifiers' => array_map(fn (string $d) => ['type' => 'dns', 'value' => $d], $this->domainNames),
                'authorizations' => [self::BASE.'/authz/1'],
                'finalize' => self::BASE.'/order/1/finalize',
            ],
        );
    }

    private function authorizationResponse(string $url): Response
    {
        return new Response([], $url, 200, [
            'identifier' => ['type' => 'dns', 'value' => $this->primaryDomain],
            'status' => 'valid',
            'expires' => now()->addHour()->toISOString(),
            'challenges' => [
                [
                    'type' => 'http-01',
                    'url' => self::BASE.'/authz/1/http-01',
                    'token' => 'test-token-123',
                    'status' => 'valid',
                ],
            ],
        ]);
    }

    private function orderResponse(): Response
    {
        $body = [
            'status' => $this->orderFinalized ? 'valid' : 'ready',
            'expires' => now()->addHour()->toISOString(),
            'identifiers' => array_map(fn (string $d) => ['type' => 'dns', 'value' => $d], $this->domainNames),
            'authorizations' => [self::BASE.'/authz/1'],
            'finalize' => self::BASE.'/order/1/finalize',
        ];

        if ($this->orderFinalized) {
            $body['certificate'] = self::BASE.'/cert/1';
        }

        return new Response([], self::BASE.'/order/1', 200, $body);
    }
}
