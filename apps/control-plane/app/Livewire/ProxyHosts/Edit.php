<?php

declare(strict_types=1);

namespace App\Livewire\ProxyHosts;

use App\Actions\ProxyHost\UpdateProxyHostAction;
use App\Enums\ForwardScheme;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Edit extends Component
{
    public ProxyHost $proxyHost;

    public string $domain = '';
    public string $forward_scheme = 'http';
    public string $forward_host = '';
    public ?int $forward_port = null;
    public bool $websockets_enabled = false;
    public ?int $ssl_certificate_id = null;
    public bool $enabled = true;

    public function mount(ProxyHost $proxy_host): void
    {
        $this->authorize('update', $proxy_host);

        $this->proxyHost = $proxy_host;
        $this->domain = $proxy_host->domain;
        $this->forward_scheme = $proxy_host->forward_scheme->value;
        $this->forward_host = $proxy_host->forward_host;
        $this->forward_port = $proxy_host->forward_port;
        $this->websockets_enabled = $proxy_host->websockets_enabled;
        $this->ssl_certificate_id = $proxy_host->ssl_certificate_id;
        $this->enabled = $proxy_host->enabled;
    }

    public function save(UpdateProxyHostAction $action): void
    {
        $data = $this->validate([
            'domain' => [
                'required', 'string', 'max:255',
                Rule::unique('proxy_hosts', 'domain')->ignore($this->proxyHost->id),
            ],
            'forward_scheme' => ['required', Rule::enum(ForwardScheme::class)],
            'forward_host' => ['required', 'string', 'max:255'],
            'forward_port' => ['required', 'integer', 'between:1,65535'],
            'websockets_enabled' => ['boolean'],
            'ssl_certificate_id' => ['nullable', 'integer', 'exists:ssl_certificates,id'],
            'enabled' => ['boolean'],
        ]);

        $action->handle($this->proxyHost, $data, auth()->user());

        $this->redirectRoute('proxy-hosts.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.proxy-hosts.edit', [
            'certificates' => SslCertificate::query()->orderBy('primary_domain')->get(),
        ]);
    }
}
