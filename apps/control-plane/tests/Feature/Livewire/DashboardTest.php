<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Dashboard;
use App\Models\ProxyHost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_counts_proxy_hosts(): void
    {
        $user = User::factory()->create();
        ProxyHost::factory()->count(2)->create(['enabled' => true]);
        ProxyHost::factory()->create(['enabled' => false]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('proxyHostsTotal', 3)
            ->assertViewHas('proxyHostsEnabled', 2)
            ->assertSee('Proxy hosts · 2 ativos');
    }
}
