<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';
require __DIR__.'/livewire.php';

// Fase 11: deliberately outside ip.allowlist, same rationale as /auth/ip-requests — the whole
// point is letting scripts/install.sh discover what IP the app actually sees a caller as (which
// depends on the Docker network setup and isn't always literally 127.0.0.1 — confirmed
// empirically: Docker Desktop for Mac's published-port NAT presents host traffic as its own
// VPNKit gateway address, not the host's real loopback) *before* anything is allowlisted yet.
// Leaks nothing but the caller's own already-known IP.
Route::get('/_install/whoami', fn (Request $request) => response($request->ip(), 200, ['Content-Type' => 'text/plain']))
    ->name('install.whoami');
