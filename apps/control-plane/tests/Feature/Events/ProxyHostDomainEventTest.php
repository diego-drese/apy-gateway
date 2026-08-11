<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\DomainEvent;
use App\Models\IpAllowlistEntry;
use App\Models\ProxyHost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProxyHostDomainEventTest extends TestCase
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
     * RecordDomainEventAction publishes via the dedicated `events` connection (no key prefix —
     * see config/database.php), so the mock has to resolve that connection back to itself
     * before `publish` can be expected on it.
     */
    private function mockRedisEventsConnection(): void
    {
        Redis::shouldReceive('connection')->with('events')->andReturnSelf();
    }

    /**
     * @return array<string, mixed>
     */
    private function assertValidRedisPayload(string $channel, string $rawPayload, string $expectedType, int $expectedVersion): array
    {
        $this->assertSame('apy-gateway:events', $channel);

        $payload = json_decode($rawPayload, true);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $payload['id'],
        );
        $this->assertSame($expectedType, $payload['type']);
        $this->assertSame($expectedVersion, $payload['version']);
        $this->assertArrayHasKey('subject_id', $payload);
        $this->assertArrayHasKey('occurred_at', $payload);

        return $payload;
    }

    public function test_creating_a_proxy_host_records_and_publishes_a_domain_event(): void
    {
        $user = $this->actingAsAllowlistedUser();

        $this->mockRedisEventsConnection();
        Redis::shouldReceive('publish')
            ->once()
            ->withArgs(function (string $channel, string $rawPayload) {
                $payload = $this->assertValidRedisPayload($channel, $rawPayload, 'proxy_host.created', 1);
                $this->assertSame($payload['subject_id'], DomainEvent::query()->sole()->subject_id);

                return true;
            });

        $response = $this->postJson('/api/proxy-hosts', [
            'domain' => 'app.example.com',
            'forward_scheme' => 'http',
            'forward_host' => '10.0.0.5',
            'forward_port' => 3000,
        ]);

        $response->assertCreated();

        $event = DomainEvent::query()->sole();
        $this->assertSame('proxy_host.created', $event->type->value);
        $this->assertSame('proxy_host', $event->getRawOriginal('subject_type'));
        $this->assertSame($response->json('data.id'), $event->subject_id);
        $this->assertSame(1, $event->payload['version']);
        $this->assertSame($user->id, $event->created_by);
    }

    public function test_updating_a_proxy_host_records_and_publishes_a_domain_event(): void
    {
        $user = $this->actingAsAllowlistedUser();
        $proxyHost = ProxyHost::factory()->create(['version' => 1]);

        $this->mockRedisEventsConnection();
        Redis::shouldReceive('publish')
            ->once()
            ->withArgs(function (string $channel, string $rawPayload) {
                $this->assertValidRedisPayload($channel, $rawPayload, 'proxy_host.updated', 2);

                return true;
            });

        $this->putJson("/api/proxy-hosts/{$proxyHost->id}", ['forward_port' => 4000])->assertOk();

        $event = DomainEvent::query()->sole();
        $this->assertSame('proxy_host.updated', $event->type->value);
        $this->assertSame($proxyHost->id, $event->subject_id);
        $this->assertSame(2, $event->payload['version']);
        $this->assertSame($user->id, $event->created_by);
    }

    public function test_deleting_a_proxy_host_records_and_publishes_a_domain_event_before_removal(): void
    {
        $user = $this->actingAsAllowlistedUser();
        $proxyHost = ProxyHost::factory()->create(['version' => 3]);

        $this->mockRedisEventsConnection();
        Redis::shouldReceive('publish')
            ->once()
            ->withArgs(function (string $channel, string $rawPayload) {
                $this->assertValidRedisPayload($channel, $rawPayload, 'proxy_host.deleted', 3);

                return true;
            });

        $this->deleteJson("/api/proxy-hosts/{$proxyHost->id}")->assertNoContent();

        $event = DomainEvent::query()->sole();
        $this->assertSame('proxy_host.deleted', $event->type->value);
        $this->assertSame($proxyHost->id, $event->subject_id);
        $this->assertSame(3, $event->payload['version']);
        $this->assertSame($user->id, $event->created_by);
        $this->assertDatabaseMissing('proxy_hosts', ['id' => $proxyHost->id]);
    }
}
