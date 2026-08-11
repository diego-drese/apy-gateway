<?php

declare(strict_types=1);

namespace App\Actions\ProxyHost;

use App\Actions\Events\RecordDomainEventAction;
use App\Enums\DomainEventType;
use App\Models\ProxyHost;
use App\Models\User;

class CreateProxyHostAction
{
    public function __construct(
        private readonly RecordDomainEventAction $recordDomainEvent,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function handle(array $data, User $creator): ProxyHost
    {
        $proxyHost = ProxyHost::query()->create([
            ...$data,
            'version' => 1,
            'created_by' => $creator->id,
        ]);

        $this->recordDomainEvent->handle(DomainEventType::ProxyHostCreated, $proxyHost, 1, $creator->id);

        return $proxyHost;
    }
}
