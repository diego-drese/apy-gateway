<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only an RFC 1123 hostname in lowercase (labels of [a-z0-9-], 1-63 chars, no leading/
 * trailing hyphen, 253 chars total). Wildcards are rejected on purpose: the agent uses the domain
 * as a file name in conf.d/ and refuses anything outside [a-z0-9.-].
 *
 * Why this matters beyond cosmetics: `proxy_hosts.domain` is rendered verbatim into the Nginx
 * `server_name` directive by the agent (nginx/templates/proxy_host.conf.tera). A value carrying
 * `;`, `{`, spaces or newlines would inject arbitrary Nginx directives on every replica.
 */
class Hostname implements ValidationRule
{
    // `D` (dollar-end-only): without it `$` also matches before a trailing "\n", which would let
    // a newline — and therefore a new Nginx directive line — through.
    public const PATTERN = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/D';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('O campo :attribute deve ser um hostname válido em minúsculas (ex.: app.exemplo.com.br).');
        }
    }
}
