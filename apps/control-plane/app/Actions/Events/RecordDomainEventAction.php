<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\DomainEventType;
use App\Models\DomainEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

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

        // `events` connection has no key prefix — the channel name is a fixed contract with
        // the Rust agent, unlike cache/queue keys which the app-wide prefix exists to namespace.
        Redis::connection('events')->publish('apy-gateway:events', json_encode([
            'id' => (string) Str::uuid(),
            'type' => $type->value,
            'subject_id' => $subject->getKey(),
            'version' => $version,
            'occurred_at' => now()->toISOString(),
        ]));

        return $domainEvent;
    }
}
