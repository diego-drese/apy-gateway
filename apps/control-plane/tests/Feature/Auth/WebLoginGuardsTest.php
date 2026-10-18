<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureIpIsAllowlisted;
use App\Models\IpAllowlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

/**
 * SPEC.md §6.2/§15: the web (Livewire) login must enforce the same IP allowlist and rate limits as
 * the JSON API login — otherwise it is a side door around both.
 */
class WebLoginGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_forbidden_for_a_non_allowlisted_ip(): void
    {
        $this->get('/login')->assertForbidden();
    }

    public function test_two_factor_page_is_forbidden_for_a_non_allowlisted_ip(): void
    {
        $this->get('/login/verify')->assertForbidden();
    }

    public function test_login_page_renders_for_an_allowlisted_ip(): void
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);

        $this->get('/login')->assertOk();
    }

    public function test_login_page_is_rate_limited(): void
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);

        // The `login` limiter also caps 5/min per "email|ip" — a page load carries no e-mail, so
        // the tighter of the two limits applies here.
        for ($i = 0; $i < 5; $i++) {
            $this->get('/login')->assertOk();
        }

        $this->get('/login')->assertTooManyRequests();
    }

    public function test_allowlist_and_throttle_also_guard_livewire_update_requests(): void
    {
        // Livewire only re-runs a page route's middleware on /livewire/update when it is
        // registered as persistent — this is what makes the guards above apply to the actual
        // credential/2FA submissions, not just to the initial page load.
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(EnsureIpIsAllowlisted::class, $persistent);
        $this->assertContains(ThrottleRequests::class, $persistent);
    }
}
