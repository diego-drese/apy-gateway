<?php

declare(strict_types=1);

namespace App\Actions\ProxyHost;

use App\Actions\Events\RecordDomainEventAction;
use App\Enums\DomainEventType;
use App\Models\ProxyHost;
use App\Models\User;

class UpdateProxyHostAction
{
    public function __construct(
        private readonly RecordDomainEventAction $recordDomainEvent,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function handle(ProxyHost $proxyHost, array $data, User $actor): ProxyHost
    {
        $proxyHost->update([
            ...$data,
            'version' => $proxyHost->version + 1,
        ]);

        $this->recordDomainEvent->handle(
            DomainEventType::ProxyHostUpdated,
            $proxyHost,
            $proxyHost->version,
            $actor->id,
        );

        return $proxyHost;
    }
}
