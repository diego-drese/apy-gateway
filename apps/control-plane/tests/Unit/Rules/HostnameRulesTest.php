<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\ForwardHost;
use App\Rules\Hostname;
use Illuminate\Contracts\Validation\ValidationRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HostnameRulesTest extends TestCase
{
    private function passes(ValidationRule $rule, mixed $value): bool
    {
        $failed = false;
        $rule->validate('field', $value, function () use (&$failed): void {
            $failed = true;
        });

        return ! $failed;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validHostnames(): array
    {
        return [
            'apex' => ['example.com'],
            'subdomain' => ['app.example.com.br'],
            'hyphen inside label' => ['my-app.example.com'],
            'single label (docker service)' => ['backend'],
            'digits' => ['123.example.com'],
            'max label length' => [str_repeat('a', 63).'.com'],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidHostnames(): array
    {
        return [
            'empty' => [''],
            'nginx directive injection' => ['example.com; include /etc/passwd'],
            'block injection' => ["example.com { }\nserver"],
            'whitespace' => ['example .com'],
            'newline' => ["example.com\n"],
            'uppercase' => ['Example.com'],
            'wildcard' => ['*.example.com'],
            'leading hyphen' => ['-example.com'],
            'trailing hyphen label' => ['example-.com'],
            'empty label' => ['example..com'],
            'trailing dot' => ['example.com.'],
            'label too long' => [str_repeat('a', 64).'.com'],
            'too long overall' => [str_repeat('a.', 127).'com'],
            'path traversal' => ['../etc/passwd'],
            'port' => ['example.com:80'],
            'not a string' => [['example.com']],
        ];
    }

    #[DataProvider('validHostnames')]
    public function test_hostname_accepts_valid_values(string $value): void
    {
        $this->assertTrue($this->passes(new Hostname(), $value));
    }

    #[DataProvider('invalidHostnames')]
    public function test_hostname_rejects_unsafe_or_malformed_values(mixed $value): void
    {
        $this->assertFalse($this->passes(new Hostname(), $value));
    }

    #[DataProvider('validHostnames')]
    public function test_forward_host_accepts_hostnames(string $value): void
    {
        $this->assertTrue($this->passes(new ForwardHost(), $value));
    }

    public function test_forward_host_accepts_ipv4(): void
    {
        $this->assertTrue($this->passes(new ForwardHost(), '10.0.0.5'));
        $this->assertTrue($this->passes(new ForwardHost(), '192.168.1.254'));
    }

    public function test_forward_host_accepts_swarm_service_names_with_underscore(): void
    {
        $this->assertTrue($this->passes(new ForwardHost(), 'mystack_web'));
        $this->assertTrue($this->passes(new ForwardHost(), 'mystack_web.mynet'));
    }

    public function test_hostname_rejects_underscore_in_public_domains(): void
    {
        $this->assertFalse($this->passes(new Hostname(), 'my_app.example.com'));
    }

    public function test_forward_host_rejects_ipv6_because_the_template_does_not_bracket_it(): void
    {
        $this->assertFalse($this->passes(new ForwardHost(), '::1'));
        $this->assertFalse($this->passes(new ForwardHost(), '[::1]'));
    }

    #[DataProvider('invalidHostnames')]
    public function test_forward_host_rejects_unsafe_or_malformed_values(mixed $value): void
    {
        $this->assertFalse($this->passes(new ForwardHost(), $value));
    }
}
