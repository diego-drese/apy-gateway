<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProxyHost
 */
class ProxyHostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'forward_scheme' => $this->forward_scheme->value,
            'forward_host' => $this->forward_host,
            'forward_port' => $this->forward_port,
            'websockets_enabled' => $this->websockets_enabled,
            'custom_config' => $this->custom_config,
            'ssl_certificate_id' => $this->ssl_certificate_id,
            'enabled' => $this->enabled,
            'version' => $this->version,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
