<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Auth;

use App\Livewire\Auth\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_correct_code_authenticates_and_redirects_to_the_dashboard(): void
    {
        $user = User::factory()->create();
        session()->put('auth.2fa.user_id', $user->id);
        Cache::put("auth.2fa.code.{$user->id}", Hash::make('123456'), now()->addMinutes(10));

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', '123456')
            ->call('verify')
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_wrong_code_shows_a_validation_error(): void
    {
        $user = User::factory()->create();
        session()->put('auth.2fa.user_id', $user->id);
        Cache::put("auth.2fa.code.{$user->id}", Hash::make('123456'), now()->addMinutes(10));

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', '999999')
            ->call('verify')
            ->assertHasErrors('code');

        $this->assertFalse(Auth::check());
    }

    public function test_deep_link_without_a_pending_login_redirects_to_login(): void
    {
        Livewire::test(TwoFactorChallenge::class)
            ->assertRedirect(route('login'));
    }
}
