<?php

declare(strict_types=1);

namespace App\Livewire\ProxyHosts;

use App\Actions\Certificate\RequestCertificateIssuanceAction;
use App\Actions\ProxyHost\UpdateProxyHostAction;
use App\Enums\ForwardScheme;
use App\Enums\ProxyHostSslOption;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use App\Rules\ForwardHost;
use App\Rules\Hostname;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Editar proxy host')]
class Edit extends Component
{
    public ProxyHost $proxyHost;

    public string $domain = '';
    public string $forward_scheme = 'http';
    public string $forward_host = '';
    public ?int $forward_port = null;
    public bool $websockets_enabled = false;
    public string $ssl_option = 'none';
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
        $this->ssl_option = $proxy_host->ssl_certificate_id === null
            ? ProxyHostSslOption::None->value
            : ProxyHostSslOption::Existing->value;
        $this->enabled = $proxy_host->enabled;
    }

    public function save(UpdateProxyHostAction $updateProxyHost, RequestCertificateIssuanceAction $requestCertificate): void
    {
        // Same normalization as the API's Form Requests (see StoreProxyHostRequest).
        $this->domain = Str::lower(trim($this->domain));
        $this->forward_host = Str::lower(trim($this->forward_host));

        $data = $this->validate([
            'domain' => [
                'required', 'string', 'max:253', new Hostname(),
                Rule::unique('proxy_hosts', 'domain')->ignore($this->proxyHost->id),
            ],
            'forward_scheme' => ['required', Rule::enum(ForwardScheme::class)],
            'forward_host' => ['required', 'string', 'max:253', new ForwardHost()],
            'forward_port' => ['required', 'integer', 'between:1,65535'],
            'websockets_enabled' => ['boolean'],
            'ssl_option' => [
                'required',
                Rule::enum(ProxyHostSslOption::class),
                // RequestCertificateIssuanceAction only accepts enabled proxy hosts; rejecting it
                // here keeps a disabled host from being saved with a request bound to fail.
                Rule::when(! $this->enabled, [Rule::notIn([ProxyHostSslOption::RequestLetsEncrypt->value])]),
            ],
            'ssl_certificate_id' => ['nullable', 'integer', 'exists:ssl_certificates,id', 'required_if:ssl_option,existing'],
            'enabled' => ['boolean'],
        ], [
            'ssl_option.not_in' => 'O proxy host precisa estar habilitado para solicitar um certificado Let\'s Encrypt.',
        ]);

        $sslOption = ProxyHostSslOption::from($data['ssl_option']);

        unset($data['ssl_option']);
        if ($sslOption !== ProxyHostSslOption::Existing) {
            $data['ssl_certificate_id'] = null;
        }

        $proxyHost = $updateProxyHost->handle($this->proxyHost, $data, auth()->user());

        if ($sslOption === ProxyHostSslOption::RequestLetsEncrypt) {
            $requestCertificate->handle([$proxyHost->domain], auth()->user());
        }

        $this->redirectRoute('proxy-hosts.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.proxy-hosts.edit', [
            'certificates' => SslCertificate::query()->orderBy('primary_domain')->get(),
        ]);
    }
}
