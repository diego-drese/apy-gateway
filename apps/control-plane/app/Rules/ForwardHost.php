<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts a lowercase hostname, a Docker service name, or an IPv4 address — the shapes the agent
 * can safely render into `proxy_pass {scheme}://{forward_host}:{port};`.
 *
 * Unlike a public domain (Hostname), an upstream may contain `_`: Docker Swarm names stack
 * services `{stack}_{service}` and that is exactly what Nginx Proxy Manager setups forward to.
 * `_` cannot break out of the directive, so allowing it costs nothing.
 *
 * IPv6 is rejected deliberately: the template does not bracket the address, so `::1` would render
 * an invalid `proxy_pass` that `nginx -t` rejects on every replica. Anything else (`;`, `}`,
 * whitespace) would inject Nginx directives, e.g. an `alias` exposing every private key the
 * replica holds.
 */
class ForwardHost implements ValidationRule
{
    // Same shape as Hostname::PATTERN plus `_` inside labels; `D` for the same trailing-"\n" reason.
    private const PATTERN = '/^(?=.{1,253}$)[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)*$/D';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('O campo :attribute deve ser um hostname ou endereço IPv4.');

            return;
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            $fail('O campo :attribute deve ser um hostname/nome de serviço (minúsculas) ou endereço IPv4.');
        }
    }
}
