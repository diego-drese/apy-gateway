<?php

declare(strict_types=1);

namespace App\Livewire\ProxyHosts;

use App\Actions\ProxyHost\CreateProxyHostAction;
use App\Enums\ForwardScheme;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public string $domain = '';
    public string $forward_scheme = 'http';
    public string $forward_host = '';
    public ?int $forward_port = null;
    public bool $websockets_enabled = false;
    public ?int $ssl_certificate_id = null;
    public bool $enabled = true;

    public function mount(): void
    {
        $this->authorize('create', ProxyHost::class);
    }

    public function save(CreateProxyHostAction $action): void
    {
        $data = $this->validate([
            'domain' => ['required', 'string', 'max:255', 'unique:proxy_hosts,domain'],
            'forward_scheme' => ['required', Rule::enum(ForwardScheme::class)],
            'forward_host' => ['required', 'string', 'max:255'],
            'forward_port' => ['required', 'integer', 'between:1,65535'],
            'websockets_enabled' => ['boolean'],
            'ssl_certificate_id' => ['nullable', 'integer', 'exists:ssl_certificates,id'],
            'enabled' => ['boolean'],
        ]);

        $action->handle($data, auth()->user());

        $this->redirectRoute('proxy-hosts.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.proxy-hosts.create', [
            'certificates' => SslCertificate::query()->orderBy('primary_domain')->get(),
        ]);
    }
}
