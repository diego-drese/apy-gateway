<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\ProxyHosts\Create;
use App\Livewire\ProxyHosts\Edit;
use App\Livewire\ProxyHosts\Index;
use App\Models\ProxyHost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

class ProxyHostsTest extends TestCase
{
    use RefreshDatabase;

    private function allowRedisPublish(): void
    {
        Redis::shouldReceive('connection')->with('events')->andReturnSelf();
        Redis::shouldReceive('publish')->zeroOrMoreTimes();
    }

    public function test_index_renders_existing_proxy_hosts(): void
    {
        $user = User::factory()->create();
        $proxyHost = ProxyHost::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee($proxyHost->domain);
    }

    public function test_create_persists_a_new_proxy_host(): void
    {
        $this->allowRedisPublish();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('domain', 'example.test')
            ->set('forward_scheme', 'http')
            ->set('forward_host', '10.0.0.5')
            ->set('forward_port', 8080)
            ->call('save')
            ->assertRedirect(route('proxy-hosts.index'));

        $this->assertDatabaseHas('proxy_hosts', ['domain' => 'example.test', 'created_by' => $user->id]);
    }

    public function test_edit_updates_an_existing_proxy_host(): void
    {
        $this->allowRedisPublish();
        $user = User::factory()->create();
        $proxyHost = ProxyHost::factory()->create(['domain' => 'old.test']);

        Livewire::actingAs($user)
            ->test(Edit::class, ['proxy_host' => $proxyHost])
            ->set('domain', 'new.test')
            ->call('save')
            ->assertRedirect(route('proxy-hosts.index'));

        $this->assertSame('new.test', $proxyHost->fresh()->domain);
    }

    public function test_delete_removes_a_proxy_host(): void
    {
        $this->allowRedisPublish();
        $user = User::factory()->create();
        $proxyHost = ProxyHost::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('delete', $proxyHost->id);

        $this->assertDatabaseMissing('proxy_hosts', ['id' => $proxyHost->id]);
    }
}
