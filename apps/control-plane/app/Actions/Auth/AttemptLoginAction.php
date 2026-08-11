<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AuditLogAction;
use App\Mail\TwoFactorCodeMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AttemptLoginAction
{
    public function handle(string $email, string $password, string $ipAddress): void
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            AuditLog::query()->create([
                'user_id' => $user?->id,
                'action' => AuditLogAction::LoginFailed->value,
                'ip_address' => $ipAddress,
                'metadata' => ['email' => $email],
            ]);

            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        $code = (string) random_int(100000, 999999);

        Cache::put(
            "auth.2fa.code.{$user->id}",
            Hash::make($code),
            now()->addMinutes(config('security.two_factor_code_ttl_minutes')),
        );

        session()->put('auth.2fa.user_id', $user->id);

        // Never log the plaintext code (CLAUDE.md: never log secrets).
        Mail::to($user->email)->send(new TwoFactorCodeMail($code));

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => AuditLogAction::LoginPasswordVerified->value,
            'ip_address' => $ipAddress,
        ]);
    }
}
