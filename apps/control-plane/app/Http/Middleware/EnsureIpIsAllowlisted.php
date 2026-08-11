<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IpAllowlistEntry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIpIsAllowlisted
{
    /**
     * SPEC.md §6.1: a request from an IP outside `ip_allowlist_entries` gets a 403 with the
     * option to request access — it never silently falls through to authentication.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isAllowlisted = IpAllowlistEntry::query()
            ->where('ip_address', $request->ip())
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if (! $isAllowlisted) {
            return response()->json([
                'message' => 'IP não autorizado. Solicite liberação de acesso.',
                'ip_access_request_url' => route('auth.ip-requests.store'),
            ], 403);
        }

        return $next($request);
    }
}
