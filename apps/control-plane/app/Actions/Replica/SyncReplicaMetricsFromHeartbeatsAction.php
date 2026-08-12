<?php

declare(strict_types=1);

namespace App\Actions\Replica;

use App\Enums\ReplicaAgentStatus;
use App\Models\ReplicaAgent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Reads the Redis heartbeat keys the Rust agent writes (`agent/src/event_bus.rs`,
 * `apy-gateway:replicas:{hostname}:heartbeat`, SPEC.md §9.2/§12) and reconciles `replica_agents`
 * — the agent itself never writes to MySQL (SPEC.md §9.4, SELECT-only), so this is the only place
 * that table is ever updated. Redis's own TTL is the liveness source of truth: a key that expired
 * (agent stopped heartbeating) simply won't be found here, which is what flips a row to Offline.
 */
class SyncReplicaMetricsFromHeartbeatsAction
{
    /**
     * @return array{online: int, offline: int}
     */
    public function handle(): array
    {
        $keys = Redis::connection('events')->keys('apy-gateway:replicas:*:heartbeat');

        $seenHostnames = [];

        if ($keys !== []) {
            $values = Redis::connection('events')->mget($keys);

            foreach (array_combine($keys, $values) as $key => $value) {
                $hostname = $this->extractHostname($key);
                if ($hostname === null) {
                    continue;
                }

                // Race between KEYS and MGET: the key can expire in between. Nothing to record.
                if ($value === null) {
                    continue;
                }

                $payload = json_decode($value, true);
                if (! is_array($payload)) {
                    Log::warning('Skipping malformed replica heartbeat payload', ['hostname' => $hostname, 'value' => $value]);

                    continue;
                }

                $seenHostnames[] = $hostname;

                ReplicaAgent::query()->updateOrCreate(
                    ['hostname' => $hostname],
                    [
                        'ip_address' => $payload['ip'] ?? '0.0.0.0',
                        'agent_version' => $payload['agent_version'] ?? 'unknown',
                        'nginx_version' => $payload['nginx_version'] ?? 'unknown',
                        'status' => ReplicaAgentStatus::Online,
                        'synced_domains_count' => $payload['synced_domains_count'] ?? null,
                        'last_heartbeat_at' => isset($payload['ts']) ? Carbon::createFromTimestamp($payload['ts']) : now(),
                    ],
                );
            }
        }

        $offline = ReplicaAgent::query()
            ->where('status', ReplicaAgentStatus::Online)
            ->whereNotIn('hostname', $seenHostnames)
            ->update(['status' => ReplicaAgentStatus::Offline]);

        return ['online' => count($seenHostnames), 'offline' => $offline];
    }

    private function extractHostname(string $key): ?string
    {
        if (! preg_match('/^apy-gateway:replicas:(.+):heartbeat$/', $key, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
