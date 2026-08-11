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
        $version = $proxyHost->version;

        $this->recordDomainEvent->handle(DomainEventType::ProxyHostDeleted, $proxyHost, $version, $actor->id);

        $proxyHost->delete();
    }
}
