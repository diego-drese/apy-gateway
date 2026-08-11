<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AuditLogAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VerifyTwoFactorCodeAction
{
    /**
     * @return array{user: User, token: string}
     */
    public function handle(string $code, string $ipAddress): array
    {
        $userId = session('auth.2fa.user_id');

        if (! $userId) {
            throw ValidationException::withMessages([
                'code' => ['Nenhum login pendente. Faça login novamente.'],
            ]);
        }

        $hashedCode = Cache::get("auth.2fa.code.{$userId}");

        if (! $hashedCode || ! Hash::check($code, $hashedCode)) {
            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => AuditLogAction::TwoFactorFailed->value,
                'ip_address' => $ipAddress,
            ]);

            throw ValidationException::withMessages([
                'code' => ['Código inválido ou expirado.'],
            ]);
        }

        Cache::forget("auth.2fa.code.{$userId}");
        session()->forget('auth.2fa.user_id');

        /** @var User $user */
        $user = User::query()->findOrFail($userId);
        $user->forceFill(['two_factor_verified_at' => now()])->save();

        Auth::login($user);
        session()->regenerate();

        $token = $user->createToken('control-plane')->plainTextToken;

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => AuditLogAction::TwoFactorVerified->value,
            'ip_address' => $ipAddress,
        ]);

        return ['user' => $user, 'token' => $token];
    }
}
