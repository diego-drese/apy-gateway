<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\DomainEventType;
use App\Models\DomainEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class RecordDomainEventAction
{
    /**
     * SPEC.md §8: every Action that changes relevant state (1) persists to `domain_events` and
     * (2) publishes to Redis. The publication is only a "wake up and check" signal — replicas
     * that miss it reconcile against the database, so it's never wrapped in the same transaction
     * as the state change itself.
     */
    public function handle(DomainEventType $type, Model $subject, int $version, ?int $actorId): DomainEvent
    {
        $domainEvent = DomainEvent::query()->create([
            'type' => $type,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'payload' => ['version' => $version],
            'created_by' => $actorId,
        ]);

        $this->publish($domainEvent, $type, $subject, $version);

        return $domainEvent;
    }

    /**
     * A Redis outage must not turn an already-committed change into a 500 for the operator: the
     * row above is the source of truth and every agent reconciles against MySQL periodically
     * (SPEC.md §9.2), so a lost publish only delays propagation — logged, never swallowed.
     */
    private function publish(DomainEvent $domainEvent, DomainEventType $type, Model $subject, int $version): void
    {
        try {
            // `events` connection has no key prefix — the channel name is a fixed contract with
            // the Rust agent, unlike cache/queue keys which the app-wide prefix exists to namespace.
            Redis::connection('events')->publish('apy-gateway:events', json_encode([
                'id' => (string) Str::uuid(),
                'type' => $type->value,
                'subject_id' => $subject->getKey(),
                'version' => $version,
                'occurred_at' => now()->toISOString(),
            ]));
        } catch (Throwable $e) {
            Log::error('Failed to publish domain event to Redis; agents will pick it up on reconciliation', [
                'domain_event_id' => $domainEvent->id,
                'type' => $type->value,
                'subject_id' => $subject->getKey(),
                'version' => $version,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
