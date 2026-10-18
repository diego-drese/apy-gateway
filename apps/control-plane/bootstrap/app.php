<?php

use App\Http\Middleware\EnsureIpIsAllowlisted;
use App\Support\Environment\SecretFileEnvironment;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

SecretFileEnvironment::load();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'ip.allowlist' => EnsureIpIsAllowlisted::class,
        ]);

        // The JSON auth endpoints (SPEC.md §6) live in the `web` group because the pending-2FA
        // state is kept in the session, but they are called by API clients that never loaded a
        // page and therefore have no CSRF token — with CSRF on, they always answered 419.
        // Exempting them is safe: login still requires the password AND a code sent to the
        // account's own e-mail, and the IP allowlist + rate limits still apply.
        $middleware->validateCsrfTokens(except: [
            'auth/login',
            'auth/login/verify',
            'auth/ip-requests',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
