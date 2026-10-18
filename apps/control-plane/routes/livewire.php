<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LogoutController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Certificates;
use App\Livewire\Dashboard;
use App\Livewire\IpAllowlist;
use App\Livewire\ProxyHosts;
use App\Livewire\Users;
use Illuminate\Support\Facades\Route;

// SPEC.md §6.2: same guards as the API's /auth/login(/verify). EnsureIpIsAllowlisted and
// ThrottleRequests are registered as Livewire persistent middleware (AppServiceProvider), so they
// also run on every /livewire/update call these pages make — that is where the password and the
// 2FA code are actually submitted.
Route::get('/login', Login::class)
    ->middleware(['guest', 'throttle:login', 'ip.allowlist'])
    ->name('login');
Route::get('/login/verify', TwoFactorChallenge::class)
    ->middleware(['guest', 'throttle:login-verify', 'ip.allowlist'])
    ->name('login.verify');
Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');

Route::middleware(['auth', 'ip.allowlist'])->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/proxy-hosts', ProxyHosts\Index::class)->name('proxy-hosts.index');
    Route::get('/proxy-hosts/create', ProxyHosts\Create::class)->name('proxy-hosts.create');
    Route::get('/proxy-hosts/{proxy_host}/edit', ProxyHosts\Edit::class)->name('proxy-hosts.edit');
    Route::get('/certificates', Certificates\Index::class)->name('certificates.index');
    Route::get('/certificates/upload', Certificates\Upload::class)->name('certificates.upload');
    Route::get('/users', Users\Index::class)->name('users.index');
    Route::get('/users/create', Users\Create::class)->name('users.create');
    Route::get('/users/{user}/edit', Users\Edit::class)->name('users.edit');
    Route::get('/ip-allowlist', IpAllowlist\Index::class)->name('ip-allowlist.index');
});
