<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SslCertificateStatus;
use App\Enums\SslCertificateType;
use App\Jobs\IssueAcmeCertificateJob;
use App\Livewire\ProxyHosts\Create;
use App\Livewire\ProxyHosts\Edit;
use App\Livewire\ProxyHosts\Index;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_index_filters_by_search_term(): void
    {
        $user = User::factory()->create();
        ProxyHost::factory()->create(['domain' => 'shop.example.test']);
        ProxyHost::factory()->create(['domain' => 'blog.example.test']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'shop')
            ->assertSee('shop.example.test')
            ->assertDontSee('blog.example.test');
    }

    public function test_create_can_request_a_lets_encrypt_certificate_after_saving(): void
    {
        $this->allowRedisPublish();
        Queue::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('domain', 'secure.example.test')
            ->set('forward_host', '10.0.0.5')
            ->set('forward_port', 8080)
            ->set('ssl_option', 'request')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('proxy-hosts.index'));

        $certificate = SslCertificate::query()
            ->where('primary_domain', 'secure.example.test')
            ->where('type', SslCertificateType::Acme)
            ->sole();
        $this->assertSame(SslCertificateStatus::Pending, $certificate->status);
        Queue::assertPushed(IssueAcmeCertificateJob::class);
    }

    public function test_create_rejects_a_certificate_request_for_a_disabled_proxy_host(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('domain', 'off.example.test')
            ->set('forward_host', '10.0.0.5')
            ->set('forward_port', 8080)
            ->set('enabled', false)
            ->set('ssl_option', 'request')
            ->call('save')
            ->assertHasErrors(['ssl_option' => 'not_in']);

        $this->assertDatabaseMissing('proxy_hosts', ['domain' => 'off.example.test']);
        Queue::assertNothingPushed();
    }

    public function test_create_without_ssl_discards_a_previously_selected_certificate(): void
    {
        $this->allowRedisPublish();
        $user = User::factory()->create();
        $certificate = SslCertificate::factory()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('domain', 'plain.example.test')
            ->set('forward_host', '10.0.0.5')
            ->set('forward_port', 8080)
            ->set('ssl_option', 'existing')
            ->set('ssl_certificate_id', $certificate->id)
            ->set('ssl_option', 'none')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('proxy_hosts', ['domain' => 'plain.example.test', 'ssl_certificate_id' => null]);
    }

    public function test_create_with_an_existing_certificate_requires_choosing_one(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('domain', 'pick.example.test')
            ->set('forward_host', '10.0.0.5')
            ->set('forward_port', 8080)
            ->set('ssl_option', 'existing')
            ->call('save')
            ->assertHasErrors(['ssl_certificate_id' => 'required_if']);
    }

    public function test_edit_preselects_the_attached_certificate_and_keeps_it(): void
    {
        $this->allowRedisPublish();
        $user = User::factory()->create();
        $proxyHost = ProxyHost::factory()->create();
        $certificateId = $proxyHost->ssl_certificate_id;
        $this->assertNotNull($certificateId);

        Livewire::actingAs($user)
            ->test(Edit::class, ['proxy_host' => $proxyHost])
            ->assertSet('ssl_option', 'existing')
            ->set('forward_port', 9090)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($certificateId, $proxyHost->fresh()->ssl_certificate_id);
    }
}
