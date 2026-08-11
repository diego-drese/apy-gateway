<?php

declare(strict_types=1);

namespace App\Http\Requests\Certificate;

use Illuminate\Foundation\Http\FormRequest;

class RequestAcmeCertificateRequest extends FormRequest
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
            'domain_names' => ['required', 'array', 'min:1'],
            'domain_names.*' => ['required', 'string', 'max:255'],
        ];
    }
}
