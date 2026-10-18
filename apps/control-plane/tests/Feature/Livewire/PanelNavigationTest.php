<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Models\IpAllowlistEntry;
use App\Models\ProxyHost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: the API used to register the same route names as the panel (`proxy-hosts.index`,
 * `certificates.index`, `users.index`, ...). Loaded later, they won, so every panel link pointed
 * at /api/* and a logged-in browser session got {"message":"Unauthenticated."}.
 */
class PanelNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_route_names_resolve_to_panel_urls(): void
    {
        $this->assertSame(url('/proxy-hosts'), route('proxy-hosts.index'));
        $this->assertSame(url('/certificates'), route('certificates.index'));
        $this->assertSame(url('/users'), route('users.index'));
        $this->assertSame(url('/ip-allowlist'), route('ip-allowlist.index'));
    }

    public function test_api_route_names_are_prefixed(): void
    {
        $this->assertSame(url('/api/proxy-hosts'), route('api.proxy-hosts.index'));
        $this->assertSame(url('/api/certificates'), route('api.certificates.index'));
        $this->assertSame(url('/api/users'), route('api.users.index'));
        $this->assertSame(url('/api/replicas'), route('api.replicas.index'));
    }

    public function test_every_panel_page_renders_for_a_logged_in_session(): void
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create();
        $proxyHost = ProxyHost::factory()->create();

        $pages = [
            '/',
            '/proxy-hosts',
            '/proxy-hosts/create',
            "/proxy-hosts/{$proxyHost->id}/edit",
            '/certificates',
            '/certificates/upload',
            '/users',
            '/users/create',
            "/users/{$user->id}/edit",
            '/ip-allowlist',
        ];

        foreach ($pages as $page) {
            $this->actingAs($user)
                ->get($page)
                ->assertOk()
                ->assertSee('href="'.url('/proxy-hosts').'"', escape: false)
                ->assertDontSee('href="'.url('/api/'), escape: false);
        }
    }
}
