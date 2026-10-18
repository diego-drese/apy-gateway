<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Enums\DomainEventType;
use App\Models\DomainEvent;
use App\Models\IpAllowlistEntry;
use App\Models\ProxyHost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * SPEC.md §8: the Redis publish is only a "wake up and check" signal; `domain_events` + periodic
 * agent reconciliation are the source of truth. A Redis outage must therefore never fail a change
 * that was already committed to MySQL.
 */
class DomainEventPublishFailureTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAllowlistedUser(): User
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function simulateRedisOutage(): void
    {
        Redis::shouldReceive('connection')->with('events')->andReturnSelf();
        Redis::shouldReceive('publish')->andThrow(new RuntimeException('Connection refused [tcp://redis:6379]'));
    }

    public function test_creating_a_proxy_host_succeeds_and_persists_the_event_when_redis_is_down(): void
    {
        $this->simulateRedisOutage();
        Log::spy();
        $this->actingAsAllowlistedUser();

        $this->postJson('/api/proxy-hosts', [
            'domain' => 'app.example.com',
            'forward_scheme' => 'http',
            'forward_host' => '10.0.0.5',
            'forward_port' => 3000,
        ])->assertCreated();

        $event = DomainEvent::query()->sole();
        $this->assertSame(DomainEventType::ProxyHostCreated, $event->type);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $context['domain_event_id'] === $event->id
                && $context['type'] === 'proxy_host.created');
    }

    public function test_deleting_a_proxy_host_removes_the_row_even_when_redis_is_down(): void
    {
        $this->simulateRedisOutage();
        $this->actingAsAllowlistedUser();
        $proxyHost = ProxyHost::factory()->create();

        $this->deleteJson("/api/proxy-hosts/{$proxyHost->id}")->assertNoContent();

        $this->assertNull($proxyHost->fresh());
        $this->assertSame(DomainEventType::ProxyHostDeleted, DomainEvent::query()->sole()->type);
    }
}
