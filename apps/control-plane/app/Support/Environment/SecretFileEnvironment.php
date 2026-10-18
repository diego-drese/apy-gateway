<?php

declare(strict_types=1);

namespace App\Support\Environment;

use RuntimeException;

/**
 * Docker secrets convention: when `{NAME}_FILE` points to a file (e.g. /run/secrets/...), its
 * content becomes `{NAME}`. In Swarm this keeps credentials out of `docker service inspect` and the
 * task environment.
 *
 * Runs from bootstrap/app.php — before Laravel reads any env var — instead of the container
 * entrypoint, because `docker exec <container> php artisan ...` (how every admin command in
 * docs/deploy/swarm.md runs) never goes through the entrypoint.
 *
 * Input: the variable names to resolve. Output: populates getenv()/$_ENV/$_SERVER. A `_FILE` that
 * points to an unreadable file is a deploy error and fails loudly instead of booting without
 * credentials.
 */
final class SecretFileEnvironment
{
    public const VARIABLES = ['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'MINIO_ACCESS_KEY', 'MINIO_SECRET_KEY', 'MAIL_PASSWORD'];

    /**
     * @param list<string> $names
     */
    public static function load(array $names = self::VARIABLES): void
    {
        foreach ($names as $name) {
            $path = getenv("{$name}_FILE");
            if ($path === false || $path === '') {
                continue;
            }

            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("{$name}_FILE points to an unreadable file: {$path}");
            }

            $value = (string) file_get_contents($path);

            $value = rtrim($value, "\r\n");
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
