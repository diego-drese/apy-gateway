<?php

declare(strict_types=1);

namespace App\Http\Requests\ProxyHost;

use App\Enums\ForwardScheme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProxyHostRequest extends FormRequest
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
            'domain' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('proxy_hosts', 'domain')->ignore($this->route('proxy_host')),
            ],
            'forward_scheme' => ['sometimes', 'required', Rule::enum(ForwardScheme::class)],
            'forward_host' => ['sometimes', 'required', 'string', 'max:255'],
            'forward_port' => ['sometimes', 'required', 'integer', 'between:1,65535'],
            'websockets_enabled' => ['sometimes', 'boolean'],
            'custom_config' => ['sometimes', 'nullable', 'array'],
            'ssl_certificate_id' => ['sometimes', 'nullable', 'integer', 'exists:ssl_certificates,id'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
