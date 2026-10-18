<?php

declare(strict_types=1);

namespace Tests\Feature\ProxyHost;

use App\Livewire\ProxyHosts\Create;
use App\Models\IpAllowlistEntry;
use App\Models\ProxyHost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `domain` and `forward_host` are rendered verbatim into every replica's Nginx config by the
 * agent — these tests pin the guarantee that nothing able to inject directives is ever persisted.
 */
class ProxyHostInputHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAllowlistedUser(): User
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function allowRedisPublish(): void
    {
        Redis::shouldReceive('connection')->with('events')->andReturnSelf();
        Redis::shouldReceive('publish')->zeroOrMoreTimes();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'domain' => 'app.example.com',
            'forward_scheme' => 'http',
            'forward_host' => '10.0.0.5',
            'forward_port' => 3000,
            ...$overrides,
        ];
    }

    public function test_store_rejects_nginx_directive_injection_in_forward_host(): void
    {
        $this->actingAsAllowlistedUser();

        $this->postJson('/api/proxy-hosts', $this->validPayload([
            'forward_host' => "10.0.0.5:80; }\nserver { listen 80; location / { alias /var/lib/apy-agent/certs/; }",
        ]))->assertUnprocessable()->assertJsonValidationErrors('forward_host');

        $this->assertSame(0, ProxyHost::query()->count());
    }

    public function test_store_rejects_nginx_directive_injection_in_domain(): void
    {
        $this->actingAsAllowlistedUser();

        $this->postJson('/api/proxy-hosts', $this->validPayload([
            'domain' => 'app.example.com; return 200',
        ]))->assertUnprocessable()->assertJsonValidationErrors('domain');
    }

    public function test_store_normalizes_domain_and_forward_host_to_lowercase(): void
    {
        $this->allowRedisPublish();
        $this->actingAsAllowlistedUser();

        $this->postJson('/api/proxy-hosts', $this->validPayload([
            'domain' => '  App.Example.COM ',
            'forward_host' => 'Backend.Internal',
        ]))->assertCreated()
            ->assertJsonPath('data.domain', 'app.example.com')
            ->assertJsonPath('data.forward_host', 'backend.internal');
    }

    public function test_store_rejects_a_domain_that_differs_from_an_existing_one_only_by_case(): void
    {
        $this->actingAsAllowlistedUser();
        ProxyHost::factory()->create(['domain' => 'app.example.com']);

        $this->postJson('/api/proxy-hosts', $this->validPayload(['domain' => 'APP.example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('domain');
    }

    public function test_update_rejects_injection_in_forward_host(): void
    {
        $this->actingAsAllowlistedUser();
        $proxyHost = ProxyHost::factory()->create(['forward_host' => '10.0.0.5']);

        $this->putJson("/api/proxy-hosts/{$proxyHost->id}", ['forward_host' => 'evil; include /etc/shadow'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('forward_host');

        $this->assertSame('10.0.0.5', $proxyHost->fresh()->forward_host);
    }

    public function test_livewire_create_rejects_injection_and_normalizes_case(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('domain', 'bad domain;')
            ->set('forward_scheme', 'http')
            ->set('forward_host', '10.0.0.5')
            ->set('forward_port', 80)
            ->call('save')
            ->assertHasErrors('domain');

        $this->allowRedisPublish();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('domain', 'Shop.Example.com')
            ->set('forward_scheme', 'http')
            ->set('forward_host', '10.0.0.5')
            ->set('forward_port', 80)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(ProxyHost::query()->where('domain', 'shop.example.com')->exists());
    }

    public function test_acme_request_rejects_malformed_domain_names_before_touching_the_database(): void
    {
        $this->actingAsAllowlistedUser();

        $this->postJson('/api/certificates/request-acme', [
            'domain_names' => ["example.com\n[req]"],
        ])->assertUnprocessable()->assertJsonValidationErrors('domain_names.0');
    }

    public function test_acme_request_rejects_duplicated_domain_names(): void
    {
        $this->actingAsAllowlistedUser();

        $this->postJson('/api/certificates/request-acme', [
            'domain_names' => ['example.com', 'example.com'],
        ])->assertUnprocessable();
    }
}
