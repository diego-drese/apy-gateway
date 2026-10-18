<?php

declare(strict_types=1);

namespace App\Actions\ProxyHost;

use App\Actions\Events\RecordDomainEventAction;
use App\Enums\DomainEventType;
use App\Models\ProxyHost;
use App\Models\User;

class DeleteProxyHostAction
{
    public function __construct(
        private readonly RecordDomainEventAction $recordDomainEvent,
    ) {
    }

    public function handle(ProxyHost $proxyHost, User $actor): void
    {
        // Delete first: publishing before the row is gone would let agents drop the config of a
        // host that still exists if the delete then failed (until the next reconciliation).
        $proxyHost->delete();

        $this->recordDomainEvent->handle(DomainEventType::ProxyHostDeleted, $proxyHost, $proxyHost->version, $actor->id);
    }
}
