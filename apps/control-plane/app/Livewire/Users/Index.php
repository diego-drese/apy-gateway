<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Actions\User\DeleteUserAction;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public function delete(int $userId, DeleteUserAction $action): void
    {
        $user = User::query()->findOrFail($userId);

        $this->authorize('delete', $user);

        $action->handle($user, auth()->user(), request()->ip());
    }

    public function render()
    {
        $this->authorize('viewAny', User::class);

        return view('livewire.users.index', [
            'users' => User::query()->latest()->paginate(15),
        ]);
    }
}
