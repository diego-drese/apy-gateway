<?php

declare(strict_types=1);

namespace Tests\Feature\Replica;

use App\Models\IpAllowlistEntry;
use App\Models\ReplicaAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReplicaApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAllowlistedUser(): User
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_returns_paginated_replicas_ordered_by_hostname(): void
    {
        $this->actingAsAllowlistedUser();
        ReplicaAgent::factory()->create(['hostname' => 'replica-b', 'synced_domains_count' => 5]);
        ReplicaAgent::factory()->create(['hostname' => 'replica-a', 'synced_domains_count' => 2]);

        $response = $this->getJson('/api/replicas');

        $response->assertOk();
        $response->assertJsonPath('data.0.hostname', 'replica-a');
        $response->assertJsonPath('data.1.hostname', 'replica-b');
        $response->assertJsonPath('data.0.synced_domains_count', 2);
        $response->assertJsonStructure([
            'data' => [['id', 'hostname', 'ip_address', 'agent_version', 'nginx_version', 'status', 'synced_domains_count', 'last_heartbeat_at', 'created_at', 'updated_at']],
        ]);
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);

        $this->getJson('/api/replicas')->assertStatus(401);
    }

    public function test_requests_from_a_non_allowlisted_ip_are_rejected_even_when_authenticated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/replicas')->assertStatus(403);
    }
}
