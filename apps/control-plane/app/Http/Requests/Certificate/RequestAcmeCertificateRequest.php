<?php

declare(strict_types=1);

namespace App\Http\Requests\Certificate;

use App\Rules\Hostname;
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
            'domain_names' => ['required', 'array', 'min:1', 'max:100'],
            // Also interpolated into the OpenSSL CSR config (subjectAltName) by
            // PerformAcmeCertificateIssuanceAction — never let a newline/`,` reach it.
            'domain_names.*' => ['required', 'string', 'max:253', 'distinct', new Hostname()],
        ];
    }
}
