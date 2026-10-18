<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Environment\SecretFileEnvironment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SecretFileEnvironmentTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['APY_TEST_SECRET', 'APY_TEST_SECRET_FILE'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function test_file_content_becomes_the_variable_without_trailing_newline(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'secret');
        file_put_contents($file, "s3cr3t\n");
        putenv("APY_TEST_SECRET_FILE={$file}");
        putenv('APY_TEST_SECRET=from-env');

        SecretFileEnvironment::load(['APY_TEST_SECRET']);

        $this->assertSame('s3cr3t', getenv('APY_TEST_SECRET'));
        $this->assertSame('s3cr3t', $_ENV['APY_TEST_SECRET']);
        $this->assertSame('s3cr3t', $_SERVER['APY_TEST_SECRET']);
    }

    public function test_plain_variable_is_untouched_when_no_file_is_configured(): void
    {
        putenv('APY_TEST_SECRET=from-env');

        SecretFileEnvironment::load(['APY_TEST_SECRET']);

        $this->assertSame('from-env', getenv('APY_TEST_SECRET'));
    }

    public function test_unreadable_secret_file_fails_loudly(): void
    {
        putenv('APY_TEST_SECRET_FILE=/run/secrets/does-not-exist');

        $this->expectException(RuntimeException::class);

        SecretFileEnvironment::load(['APY_TEST_SECRET']);
    }
}
