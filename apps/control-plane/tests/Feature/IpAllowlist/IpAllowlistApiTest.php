<?php

declare(strict_types=1);

namespace Tests\Feature\IpAllowlist;

use App\Models\AuditLog;
use App\Models\IpAllowlistEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IpAllowlistApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAllowlistedUser(): User
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_returns_paginated_entries(): void
    {
        $this->actingAsAllowlistedUser();
        IpAllowlistEntry::factory()->count(2)->create();

        $response = $this->getJson('/api/ip-allowlist-entries');

        $response->assertOk();
        // The 127.0.0.1 entry from actingAsAllowlistedUser() + 2 factory entries.
        $this->assertCount(3, $response->json('data'));
    }

    public function test_store_creates_entry_directly_without_email_flow(): void
    {
        $actor = $this->actingAsAllowlistedUser();

        $response = $this->postJson('/api/ip-allowlist-entries', [
            'ip_address' => '203.0.113.5',
            'label' => 'Office VPN',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.ip_address', '203.0.113.5');
        $response->assertJsonPath('data.approved_by', $actor->id);

        $log = AuditLog::query()->where('action', 'ip_allowlist_entry.created')->sole();
        $this->assertSame($actor->id, $log->user_id);
    }

    public function test_destroy_revokes_an_entry(): void
    {
        $actor = $this->actingAsAllowlistedUser();
        $entry = IpAllowlistEntry::factory()->create(['ip_address' => '203.0.113.9']);

        $this->deleteJson("/api/ip-allowlist-entries/{$entry->id}")->assertNoContent();

        $this->assertDatabaseMissing('ip_allowlist_entries', ['id' => $entry->id]);

        $log = AuditLog::query()->where('action', 'ip_allowlist_entry.deleted')->sole();
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame('203.0.113.9', $log->metadata['ip_address']);
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);

        $this->getJson('/api/ip-allowlist-entries')->assertStatus(401);
    }

    public function test_requests_from_a_non_allowlisted_ip_are_rejected_even_when_authenticated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/ip-allowlist-entries')->assertStatus(403);
    }
}
