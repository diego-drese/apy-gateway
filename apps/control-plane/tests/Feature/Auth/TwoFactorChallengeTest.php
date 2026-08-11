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

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    private function loginAndCaptureCode(): array
    {
        Mail::fake();
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        $code = null;
        Mail::assertQueued(TwoFactorCodeMail::class, function (TwoFactorCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return [$user, $code];
    }

    public function test_correct_code_authenticates_and_returns_a_token(): void
    {
        [$user, $code] = $this->loginAndCaptureCode();

        $response = $this->postJson('/auth/login/verify', ['code' => $code]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
        $this->assertTrue(Auth::check());
        $this->assertTrue(Auth::id() === $user->id);
        $this->assertNotNull($user->fresh()->two_factor_verified_at);
        $this->assertSame('two_factor.verified', AuditLog::query()->latest('id')->first()->action);
    }

    public function test_wrong_code_is_rejected_and_audited(): void
    {
        [, $code] = $this->loginAndCaptureCode();
        $wrongCode = $code === '111111' ? '222222' : '111111';

        $response = $this->postJson('/auth/login/verify', ['code' => $wrongCode]);

        $response->assertStatus(422);
        $this->assertFalse(Auth::check());
        $this->assertSame('two_factor.failed', AuditLog::query()->latest('id')->first()->action);
    }

    public function test_expired_code_is_rejected(): void
    {
        [, $code] = $this->loginAndCaptureCode();

        $this->travel(config('security.two_factor_code_ttl_minutes') + 1)->minutes();

        $this->postJson('/auth/login/verify', ['code' => $code])->assertStatus(422);
    }

    public function test_verifying_without_a_pending_login_fails(): void
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);

        $this->postJson('/auth/login/verify', ['code' => '123456'])->assertStatus(422);
    }
}
