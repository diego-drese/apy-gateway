<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Auth\VerifyTwoFactorCodeAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class TwoFactorChallenge extends Component
{
    public string $code = '';

    public function mount(): void
    {
        // Deep-linked directly without a pending login — nothing to verify against.
        if (! session()->has('auth.2fa.user_id')) {
            $this->redirectRoute('login', navigate: true);
        }
    }

    public function verify(VerifyTwoFactorCodeAction $action): void
    {
        $this->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $action->handle($this->code, request()->ip());

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.two-factor-challenge');
    }
}
