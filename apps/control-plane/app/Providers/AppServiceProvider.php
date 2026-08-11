<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\IpAccessRequest;
use App\Models\IpAllowlistEntry;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Polymorphic subject_type columns (domain_events, audit_logs) store these short
        // aliases instead of FQCNs, so stored data never depends on our namespace layout.
        Relation::enforceMorphMap([
            'proxy_host' => ProxyHost::class,
            'ssl_certificate' => SslCertificate::class,
            'user' => User::class,
            'ip_allowlist_entry' => IpAllowlistEntry::class,
            'ip_access_request' => IpAccessRequest::class,
        ]);

        // SPEC.md §15: rate limiting is required on every auth route.
        RateLimiter::for('ip-requests', fn (Request $request) => Limit::perMinutes(15, 5)->by($request->ip()));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        RateLimiter::for('login-verify', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinutes(15, 5)->by($request->ip()));
    }
}
