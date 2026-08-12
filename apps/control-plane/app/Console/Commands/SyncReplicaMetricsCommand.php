<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Replica\SyncReplicaMetricsFromHeartbeatsAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SPEC.md §12: sync metrics per replica. Thin orchestration only — reading Redis and reconciling
 * `replica_agents` is SyncReplicaMetricsFromHeartbeatsAction's job.
 */
class SyncReplicaMetricsCommand extends Command
{
    protected $signature = 'replicas:sync-metrics';

    protected $description = 'Reconcile replica_agents from the Redis heartbeats the Rust agents write';

    public function handle(SyncReplicaMetricsFromHeartbeatsAction $syncMetrics): int
    {
        $result = $syncMetrics->handle();

        Log::info('Synced replica metrics from heartbeats', $result);

        $this->info("Online: {$result['online']}, flipped to offline: {$result['offline']}.");

        return self::SUCCESS;
    }
}
