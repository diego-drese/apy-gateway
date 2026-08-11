<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\IpAllowlist\Index;
use App\Models\IpAccessRequest;
use App\Models\IpAllowlistEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IpAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_entries_and_pending_requests(): void
    {
        $user = User::factory()->create();
        $entry = IpAllowlistEntry::factory()->create();
        $pending = IpAccessRequest::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee($entry->ip_address)
            ->assertSee($pending->ip_address);
    }

    public function test_add_entry_creates_an_allowlist_entry(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('ip_address', '203.0.113.10')
            ->set('label', 'office')
            ->call('addEntry');

        $this->assertDatabaseHas('ip_allowlist_entries', ['ip_address' => '203.0.113.10', 'approved_by' => $user->id]);
    }

    public function test_revoke_removes_an_entry(): void
    {
        $user = User::factory()->create();
        $entry = IpAllowlistEntry::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('revoke', $entry->id);

        $this->assertDatabaseMissing('ip_allowlist_entries', ['id' => $entry->id]);
    }

    public function test_approve_converts_a_pending_request_into_an_allowlist_entry(): void
    {
        $user = User::factory()->create();
        $pending = IpAccessRequest::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('approve', $pending->id);

        $this->assertDatabaseHas('ip_allowlist_entries', ['ip_address' => $pending->ip_address, 'approved_by' => $user->id]);
    }
}
