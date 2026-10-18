<?php

declare(strict_types=1);

namespace App\Livewire\ProxyHosts;

use App\Actions\Certificate\RequestCertificateIssuanceAction;
use App\Actions\ProxyHost\CreateProxyHostAction;
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
#[Title('Novo proxy host')]
class Create extends Component
{
    public string $domain = '';
    public string $forward_scheme = 'http';
    public string $forward_host = '';
    public ?int $forward_port = null;
    public bool $websockets_enabled = false;
    public string $ssl_option = 'none';
    public ?int $ssl_certificate_id = null;
    public bool $enabled = true;

    public function mount(): void
    {
        $this->authorize('create', ProxyHost::class);
    }

    public function save(CreateProxyHostAction $createProxyHost, RequestCertificateIssuanceAction $requestCertificate): void
    {
        // Same normalization as the API's Form Requests (see StoreProxyHostRequest).
        $this->domain = Str::lower(trim($this->domain));
        $this->forward_host = Str::lower(trim($this->forward_host));

        $data = $this->validate([
            'domain' => ['required', 'string', 'max:253', new Hostname(), 'unique:proxy_hosts,domain'],
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

        $proxyHost = $createProxyHost->handle($data, auth()->user());

        if ($sslOption === ProxyHostSslOption::RequestLetsEncrypt) {
            $requestCertificate->handle([$proxyHost->domain], auth()->user());
        }

        $this->redirectRoute('proxy-hosts.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.proxy-hosts.create', [
            'certificates' => SslCertificate::query()->orderBy('primary_domain')->get(),
        ]);
    }
}
