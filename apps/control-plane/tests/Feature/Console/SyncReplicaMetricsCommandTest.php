<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ReplicaAgentStatus;
use App\Models\ReplicaAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SyncReplicaMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function mockRedisEventsConnection(): void
    {
        Redis::shouldReceive('connection')->with('events')->andReturnSelf();
    }

    public function test_creates_a_new_replica_from_a_fresh_heartbeat(): void
    {
        $this->mockRedisEventsConnection();
        Redis::shouldReceive('keys')->with('apy-gateway:replicas:*:heartbeat')
            ->andReturn(['apy-gateway:replicas:agent-1:heartbeat']);
        Redis::shouldReceive('mget')->with(['apy-gateway:replicas:agent-1:heartbeat'])
            ->andReturn([json_encode([
                'ts' => now()->timestamp,
                'ip' => '10.0.0.5',
                'agent_version' => '0.1.0',
                'nginx_version' => '1.27.4',
                'synced_domains_count' => 3,
            ])]);

        $this->artisan('replicas:sync-metrics')->assertSuccessful();

        $replica = ReplicaAgent::query()->where('hostname', 'agent-1')->sole();
        $this->assertSame('10.0.0.5', $replica->ip_address);
        $this->assertSame('0.1.0', $replica->agent_version);
        $this->assertSame('1.27.4', $replica->nginx_version);
        $this->assertSame(3, $replica->synced_domains_count);
        $this->assertSame(ReplicaAgentStatus::Online, $replica->status);
        $this->assertNotNull($replica->last_heartbeat_at);
    }

    public function test_refreshes_an_existing_replicas_fields(): void
    {
        $existing = ReplicaAgent::factory()->create([
            'hostname' => 'agent-1',
            'synced_domains_count' => 1,
            'status' => ReplicaAgentStatus::Offline,
        ]);

        $this->mockRedisEventsConnection();
        Redis::shouldReceive('keys')->andReturn(['apy-gateway:replicas:agent-1:heartbeat']);
        Redis::shouldReceive('mget')->andReturn([json_encode([
            'ts' => now()->timestamp,
            'ip' => '10.0.0.9',
            'agent_version' => '0.2.0',
            'nginx_version' => '1.27.4',
            'synced_domains_count' => 7,
        ])]);

        $this->artisan('replicas:sync-metrics')->assertSuccessful();

        $existing->refresh();
        $this->assertSame(1, ReplicaAgent::query()->count());
        $this->assertSame('10.0.0.9', $existing->ip_address);
        $this->assertSame(7, $existing->synced_domains_count);
        $this->assertSame(ReplicaAgentStatus::Online, $existing->status);
    }

    public function test_skips_a_malformed_payload_without_crashing(): void
    {
        $this->mockRedisEventsConnection();
        Redis::shouldReceive('keys')->andReturn(['apy-gateway:replicas:agent-1:heartbeat']);
        Redis::shouldReceive('mget')->andReturn(['not json']);

        $this->artisan('replicas:sync-metrics')->assertSuccessful();

        $this->assertDatabaseCount('replica_agents', 0);
    }

    public function test_skips_a_key_that_expired_between_keys_and_mget(): void
    {
        $this->mockRedisEventsConnection();
        Redis::shouldReceive('keys')->andReturn(['apy-gateway:replicas:agent-1:heartbeat']);
        Redis::shouldReceive('mget')->andReturn([null]);

        $this->artisan('replicas:sync-metrics')->assertSuccessful();

        $this->assertDatabaseCount('replica_agents', 0);
    }

    public function test_flips_a_replica_with_no_matching_heartbeat_to_offline(): void
    {
        $stale = ReplicaAgent::factory()->create(['hostname' => 'gone', 'status' => ReplicaAgentStatus::Online]);

        $this->mockRedisEventsConnection();
        Redis::shouldReceive('keys')->andReturn([]);

        $this->artisan('replicas:sync-metrics')->assertSuccessful();

        $this->assertSame(ReplicaAgentStatus::Offline, $stale->fresh()->status);
    }

    public function test_does_not_touch_a_replica_already_offline(): void
    {
        $alreadyOffline = ReplicaAgent::factory()->create([
            'hostname' => 'gone',
            'status' => ReplicaAgentStatus::Offline,
        ]);
        $updatedAt = $alreadyOffline->updated_at;

        $this->travel(1)->minute();

        $this->mockRedisEventsConnection();
        Redis::shouldReceive('keys')->andReturn([]);

        $this->artisan('replicas:sync-metrics')->assertSuccessful();

        $this->assertTrue($alreadyOffline->fresh()->updated_at->equalTo($updatedAt));
    }
}
