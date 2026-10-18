<?php

declare(strict_types=1);

namespace App\Http\Requests\ProxyHost;

use App\Enums\ForwardScheme;
use App\Rules\ForwardHost;
use App\Rules\Hostname;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProxyHostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Hostnames are case-insensitive, but the agent names conf.d files after the lowercased domain
     * — normalizing here keeps `Example.com` and `example.com` from passing the unique check as two
     * different rows that would overwrite each other's config file on every replica.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(collect(['domain', 'forward_host'])
            ->filter(fn (string $field) => is_string($this->input($field)))
            ->mapWithKeys(fn (string $field) => [$field => Str::lower(trim($this->input($field)))])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => [
                'sometimes', 'required', 'string', 'max:253', new Hostname(),
                Rule::unique('proxy_hosts', 'domain')->ignore($this->route('proxy_host')),
            ],
            'forward_scheme' => ['sometimes', 'required', Rule::enum(ForwardScheme::class)],
            'forward_host' => ['sometimes', 'required', 'string', 'max:253', new ForwardHost()],
            'forward_port' => ['sometimes', 'required', 'integer', 'between:1,65535'],
            'websockets_enabled' => ['sometimes', 'boolean'],
            'custom_config' => ['sometimes', 'nullable', 'array'],
            'ssl_certificate_id' => ['sometimes', 'nullable', 'integer', 'exists:ssl_certificates,id'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
