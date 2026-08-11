<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SslCertificate
 *
 * Never exposes `storage_path` — internal MinIO object key, not part of the public contract
 * (SPEC.md §15: the API returns only metadata, never key material).
 */
class SslCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'primary_domain' => $this->primary_domain,
            'domain_names' => $this->domain_names,
            'provider' => $this->provider->value,
            'status' => $this->status->value,
            'content_hash' => $this->content_hash,
            'version' => $this->version,
            'issued_at' => $this->issued_at,
            'expires_at' => $this->expires_at,
            'last_renewal_attempt_at' => $this->last_renewal_attempt_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
