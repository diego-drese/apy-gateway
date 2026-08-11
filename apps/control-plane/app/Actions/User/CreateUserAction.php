<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\AuditLogAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUserAction
{
    public function handle(string $name, string $email, User $actor, string $ipAddress): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            // Unusable placeholder — the account can't be logged into until the user sets
            // their own password via the emailed reset link below (never assign/transmit a
            // real password directly).
            'password' => Hash::make(Str::random(64)),
        ]);

        Password::sendResetLink(['email' => $email]);

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => AuditLogAction::UserCreated->value,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'ip_address' => $ipAddress,
            'metadata' => ['email' => $email],
        ]);

        return $user;
    }
}
