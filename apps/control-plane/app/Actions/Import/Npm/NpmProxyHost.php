<?php

declare(strict_types=1);

namespace App\Actions\Import\Npm;

/**
 * One proxy host as exported by Nginx Proxy Manager's API (`GET /api/nginx/proxy-hosts`).
 *
 * Parsing is deliberately tolerant about *types* (NPM versions differ: booleans may come as
 * 0/1) but never about *meaning* — anything apy-gateway cannot reproduce faithfully is surfaced by
 * blockingFeatures()/ignoredFeatures() instead of being silently dropped.
 */
final class NpmProxyHost
{
    /**
     * @param list<string> $domainNames
     */
    public function __construct(
        public readonly int $id,
        public readonly array $domainNames,
        public readonly string $forwardScheme,
        public readonly string $forwardHost,
        public readonly int $forwardPort,
        public readonly bool $websocketsEnabled,
        public readonly bool $enabled,
        public readonly ?int $certificateId,
        public readonly bool $sslForced,
        public readonly int $accessListId,
        public readonly string $advancedConfig,
        public readonly int $customLocationsCount,
        public readonly bool $cachingEnabled,
        public readonly bool $blockExploits,
        public readonly bool $hstsEnabled,
        public readonly bool $http2Support,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $certificateId = (int) ($raw['certificate_id'] ?? 0);

        return new self(
            id: (int) ($raw['id'] ?? 0),
            domainNames: array_values(array_map(
                fn (mixed $domain) => strtolower(trim((string) $domain)),
                (array) ($raw['domain_names'] ?? []),
            )),
            forwardScheme: strtolower((string) ($raw['forward_scheme'] ?? 'http')),
            forwardHost: strtolower(trim((string) ($raw['forward_host'] ?? ''))),
            forwardPort: (int) ($raw['forward_port'] ?? 0),
            websocketsEnabled: (bool) ($raw['allow_websocket_upgrade'] ?? false),
            enabled: (bool) ($raw['enabled'] ?? true),
            certificateId: $certificateId > 0 ? $certificateId : null,
            sslForced: (bool) ($raw['ssl_forced'] ?? false),
            accessListId: (int) ($raw['access_list_id'] ?? 0),
            advancedConfig: trim((string) ($raw['advanced_config'] ?? '')),
            customLocationsCount: count((array) ($raw['locations'] ?? [])),
            cachingEnabled: (bool) ($raw['caching_enabled'] ?? false),
            blockExploits: (bool) ($raw['block_exploits'] ?? false),
            hstsEnabled: (bool) ($raw['hsts_enabled'] ?? false),
            http2Support: (bool) ($raw['http2_support'] ?? false),
        );
    }

    /**
     * Features whose absence changes *who can reach what*, or the routing itself. Importing such a
     * host without them could expose a protected app or break it, so the import skips it unless the
     * operator explicitly accepts the difference (--include-unsupported).
     *
     * @return list<string>
     */
    public function blockingFeatures(): array
    {
        return array_values(array_filter([
            $this->accessListId > 0 ? 'access list (autenticação/IP do NPM)' : null,
            $this->advancedConfig !== '' ? 'advanced config (diretivas Nginx customizadas)' : null,
            $this->customLocationsCount > 0 ? "custom locations ({$this->customLocationsCount})" : null,
        ]));
    }

    /**
     * Features apy-gateway does not implement but whose absence does not change access control —
     * imported anyway, reported so the operator knows exactly what differs.
     *
     * @return list<string>
     */
    public function ignoredFeatures(): array
    {
        return array_values(array_filter([
            $this->cachingEnabled ? 'cache assets' : null,
            $this->blockExploits ? 'block common exploits' : null,
            $this->hstsEnabled ? 'HSTS' : null,
            $this->http2Support ? 'HTTP/2' : null,
            // apy-gateway always redirects :80 -> :443 when a certificate is attached.
            $this->certificateId !== null && ! $this->sslForced ? 'HTTP sem redirect pra HTTPS (aqui sempre redireciona)' : null,
        ]));
    }
}
