<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ReplicaAgent
 */
class ReplicaAgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hostname' => $this->hostname,
            'ip_address' => $this->ip_address,
            'agent_version' => $this->agent_version,
            'nginx_version' => $this->nginx_version,
            'status' => $this->status->value,
            'synced_domains_count' => $this->synced_domains_count,
            'last_heartbeat_at' => $this->last_heartbeat_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
