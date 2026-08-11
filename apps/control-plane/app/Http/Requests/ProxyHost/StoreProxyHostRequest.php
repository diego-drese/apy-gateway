<?php

declare(strict_types=1);

namespace App\Http\Requests\ProxyHost;

use App\Enums\ForwardScheme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProxyHostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:255', 'unique:proxy_hosts,domain'],
            'forward_scheme' => ['required', Rule::enum(ForwardScheme::class)],
            'forward_host' => ['required', 'string', 'max:255'],
            'forward_port' => ['required', 'integer', 'between:1,65535'],
            'websockets_enabled' => ['sometimes', 'boolean'],
            'custom_config' => ['nullable', 'array'],
            'ssl_certificate_id' => ['nullable', 'integer', 'exists:ssl_certificates,id'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
