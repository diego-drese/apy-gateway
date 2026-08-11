<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Auth;

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_correct_credentials_redirect_to_the_two_factor_challenge(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertRedirect(route('login.verify'));

        $this->assertSame($user->id, session('auth.2fa.user_id'));
    }

    public function test_wrong_password_shows_a_validation_error(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertNull(session('auth.2fa.user_id'));
    }
}
