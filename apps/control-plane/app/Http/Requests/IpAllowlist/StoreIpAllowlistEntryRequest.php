<?php

declare(strict_types=1);

namespace App\Http\Requests\IpAllowlist;

use Illuminate\Foundation\Http\FormRequest;

class StoreIpAllowlistEntryRequest extends FormRequest
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
            'ip_address' => ['required', 'ip', 'max:45'],
            'label' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
