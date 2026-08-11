<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Actions\User\UpdateUserAction;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Edit extends Component
{
    public User $user;

    public string $name = '';
    public string $email = '';

    public function mount(User $user): void
    {
        $this->authorize('update', $user);

        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function save(UpdateUserAction $action): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user->id),
            ],
        ]);

        $action->handle($this->user, $data, auth()->user(), request()->ip());

        $this->redirectRoute('users.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.users.edit');
    }
}
