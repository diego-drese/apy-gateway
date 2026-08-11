<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Actions\User\CreateUserAction;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public string $name = '';
    public string $email = '';

    public function mount(): void
    {
        $this->authorize('create', User::class);
    }

    public function save(CreateUserAction $action): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $action->handle($this->name, $this->email, auth()->user(), request()->ip());

        $this->redirectRoute('users.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.users.create');
    }
}
