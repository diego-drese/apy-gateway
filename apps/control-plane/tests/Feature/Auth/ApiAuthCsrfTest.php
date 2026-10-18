<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Laravel skips CSRF verification while running tests, so a regression here would be invisible to
 * every other feature test — found by scripts/e2e-test.sh, where POST /auth/login answered 419.
 */
class ApiAuthCsrfTest extends TestCase
{
    private function isExempt(string $path): bool
    {
        $middleware = app(ValidateCsrfToken::class);
        $method = new ReflectionMethod($middleware, 'inExceptArray');

        return $method->invoke($middleware, Request::create($path, 'POST'));
    }

    public function test_json_auth_endpoints_are_exempt_from_csrf(): void
    {
        $this->assertTrue($this->isExempt('/auth/login'));
        $this->assertTrue($this->isExempt('/auth/login/verify'));
        $this->assertTrue($this->isExempt('/auth/ip-requests'));
    }

    public function test_session_backed_web_actions_still_require_csrf(): void
    {
        $this->assertFalse($this->isExempt('/logout'));
        $this->assertFalse($this->isExempt('/livewire/update'));
    }
}
