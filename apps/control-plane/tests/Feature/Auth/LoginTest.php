<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Mail\TwoFactorCodeMail;
use App\Models\AuditLog;
use App\Models\IpAllowlistEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_from_a_non_allowlisted_ip_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertStatus(403);
    }

    public function test_wrong_password_fails_and_is_audited(): void
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertFalse(Auth::check());
        $this->assertSame('login.failed', AuditLog::query()->sole()->action);
    }

    public function test_correct_credentials_trigger_two_factor_email_without_authenticating_yet(): void
    {
        Mail::fake();
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()->assertJson(['two_factor_required' => true]);
        $this->assertFalse(Auth::check());
        Mail::assertQueued(TwoFactorCodeMail::class, fn (TwoFactorCodeMail $mail) => $mail->hasTo($user->email));
    }
}
