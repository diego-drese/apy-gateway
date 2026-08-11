<?php

declare(strict_types=1);

namespace App\Enums;

enum ReplicaAgentStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
}
