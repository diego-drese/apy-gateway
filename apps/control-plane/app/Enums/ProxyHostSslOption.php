<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The SSL choice offered by the panel's proxy host form (same three options as NPM's SSL tab).
 * Purely a UI concept — it is never persisted; it only decides whether ssl_certificate_id is kept
 * and whether RequestCertificateIssuanceAction runs after the proxy host is saved.
 */
enum ProxyHostSslOption: string
{
    case None = 'none';
    case Existing = 'existing';
    // Let's Encrypt HTTP-01 needs the proxy host's `:80` server block to already exist, so the
    // request can only happen after the proxy host is saved — never before.
    case RequestLetsEncrypt = 'request';
}
