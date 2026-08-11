<?php

declare(strict_types=1);

namespace Tests\Feature\ProxyHost;

use App\Models\IpAllowlistEntry;
use App\Models\ProxyHost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProxyHostApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAllowlistedUser(): User
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Every proxy-host mutation now records a domain event and publishes to Redis
     * (SPEC.md §8) — these tests aren't about that side effect, just unblock the call.
     */
    private function allowRedisPublish(): void
    {
        Redis::shouldReceive('connection')->with('events')->andReturnSelf();
        Redis::shouldReceive('publish')->zeroOrMoreTimes();
    }

    public function test_index_returns_paginated_proxy_hosts(): void
    {
        $this->actingAsAllowlistedUser();
        ProxyHost::factory()->count(3)->create();

        $response = $this->getJson('/api/proxy-hosts');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_store_creates_proxy_host_with_creator_and_default_version(): void
    {
        $this->allowRedisPublish();
        $user = $this->actingAsAllowlistedUser();

        $response = $this->postJson('/api/proxy-hosts', [
            'domain' => 'app.example.com',
            'forward_scheme' => 'http',
            'forward_host' => '10.0.0.5',
            'forward_port' => 3000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.domain', 'app.example.com');
        $response->assertJsonPath('data.version', 1);
        $response->assertJsonPath('data.created_by', $user->id);
    }

    public function test_store_rejects_duplicate_domain(): void
    {
        $this->actingAsAllowlistedUser();
        ProxyHost::factory()->create(['domain' => 'taken.example.com']);

        $this->postJson('/api/proxy-hosts', [
            'domain' => 'taken.example.com',
            'forward_scheme' => 'http',
            'forward_host' => '10.0.0.5',
            'forward_port' => 3000,
        ])->assertStatus(422);
    }

    public function test_update_increments_version(): void
    {
        $this->allowRedisPublish();
        $this->actingAsAllowlistedUser();
        $proxyHost = ProxyHost::factory()->create(['version' => 1]);

        $response = $this->putJson("/api/proxy-hosts/{$proxyHost->id}", [
            'forward_port' => 4000,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.forward_port', 4000);
        $response->assertJsonPath('data.version', 2);
    }

    public function test_destroy_removes_the_proxy_host(): void
    {
        $this->allowRedisPublish();
        $this->actingAsAllowlistedUser();
        $proxyHost = ProxyHost::factory()->create();

        $this->deleteJson("/api/proxy-hosts/{$proxyHost->id}")->assertNoContent();

        $this->assertDatabaseMissing('proxy_hosts', ['id' => $proxyHost->id]);
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);

        $this->getJson('/api/proxy-hosts')->assertStatus(401);
    }

    public function test_requests_from_a_non_allowlisted_ip_are_rejected_even_when_authenticated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/proxy-hosts')->assertStatus(403);
    }
}
