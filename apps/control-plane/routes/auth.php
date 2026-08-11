<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\IpAccessRequestController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Livewire\Auth\SetPassword;
use Illuminate\Support\Facades\Route;

// SPEC.md §6.0: reachable from any IP already inside the cluster's private network — this is
// the escape hatch, so it must never sit behind the ip.allowlist middleware.
Route::post('/auth/ip-requests', [IpAccessRequestController::class, 'store'])
    ->middleware('throttle:ip-requests')
    ->name('auth.ip-requests.store');

Route::get('/auth/ip-requests/{ipAccessRequest:token}/approve', [IpAccessRequestController::class, 'approve'])
    ->name('auth.ip-requests.approve');

// SPEC.md §6.2: IP must already be allowlisted before login is even attempted.
Route::post('/auth/login', [LoginController::class, 'store'])
    ->middleware(['throttle:login', 'ip.allowlist'])
    ->name('auth.login');

Route::post('/auth/login/verify', [TwoFactorChallengeController::class, 'store'])
    ->middleware(['throttle:login-verify', 'ip.allowlist'])
    ->name('auth.login.verify');

// Outside ip.allowlist for the same reason as /auth/ip-requests: a freshly-invited user isn't
// allowlisted yet. Route name is a hard requirement — Laravel's built-in ResetPassword
// notification (Password::sendResetLink()) hardcodes route('password.reset', ...).
Route::get('/auth/set-password/{token}', SetPassword::class)
    ->middleware('throttle:password-reset')
    ->name('password.reset');
