<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Auth\AttemptLoginAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class Login extends Component
{
    public string $email = '';
    public string $password = '';

    public function login(AttemptLoginAction $action): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // ValidationException thrown here (wrong credentials) is caught automatically by
        // Livewire's SupportValidation feature and converted into $errors, same as a normal
        // Laravel request — no manual try/catch needed.
        $action->handle($this->email, $this->password, request()->ip());

        $this->redirectRoute('login.verify', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
