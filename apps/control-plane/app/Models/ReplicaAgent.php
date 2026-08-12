<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReplicaAgentStatus;
use Database\Factories\ReplicaAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hostname', 'ip_address', 'agent_version', 'nginx_version', 'status', 'synced_domains_count', 'last_heartbeat_at'])]
class ReplicaAgent extends Model
{
    /** @use HasFactory<ReplicaAgentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReplicaAgentStatus::class,
            'synced_domains_count' => 'integer',
            'last_heartbeat_at' => 'datetime',
        ];
    }
}
