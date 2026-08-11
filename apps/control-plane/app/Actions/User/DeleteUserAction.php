<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\AuditLogAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class DeleteUserAction
{
    public function handle(User $user, User $actor, string $ipAddress): void
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages([
                'user' => ['Você não pode excluir sua própria conta.'],
            ]);
        }

        if (User::query()->count() <= 1) {
            throw ValidationException::withMessages([
                'user' => ['Não é possível excluir o último usuário do sistema.'],
            ]);
        }

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => AuditLogAction::UserDeleted->value,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'ip_address' => $ipAddress,
            'metadata' => ['email' => $user->email],
        ]);

        $user->delete();
    }
}
