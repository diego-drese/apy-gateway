<?php

declare(strict_types=1);

namespace App\Http\Requests\Certificate;

use Illuminate\Foundation\Http\FormRequest;

class UploadCertificateRequest extends FormRequest
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
            'certificate' => ['required', 'string', 'max:32768'],
            'private_key' => ['required', 'string', 'max:32768'],
        ];
    }
}
