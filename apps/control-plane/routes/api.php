<?php

declare(strict_types=1);

use App\Http\Controllers\CertificateController;
use App\Http\Controllers\IpAccessRequestController;
use App\Http\Controllers\IpAllowlistController;
use App\Http\Controllers\ProxyHostController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('proxy-hosts', ProxyHostController::class)
    ->middleware(['auth:sanctum', 'ip.allowlist']);

Route::apiResource('users', UserController::class)
    ->middleware(['auth:sanctum', 'ip.allowlist']);

Route::apiResource('ip-allowlist-entries', IpAllowlistController::class)
    ->only(['index', 'store', 'destroy'])
    ->middleware(['auth:sanctum', 'ip.allowlist']);

Route::middleware(['auth:sanctum', 'ip.allowlist'])->group(function () {
    Route::get('/certificates', [CertificateController::class, 'index'])->name('certificates.index');
    Route::get('/certificates/{certificate}', [CertificateController::class, 'show'])->name('certificates.show');
    Route::post('/certificates/upload', [CertificateController::class, 'upload'])->name('certificates.upload');
    Route::post('/certificates/request-acme', [CertificateController::class, 'requestAcme'])->name('certificates.request-acme');

    Route::get('/ip-access-requests', [IpAccessRequestController::class, 'index'])->name('ip-access-requests.index');
    Route::post('/ip-access-requests/{ip_access_request}/approve', [IpAccessRequestController::class, 'approve'])
        ->name('ip-access-requests.approve');
});
