<?php

declare(strict_types=1);

namespace App\Enums;

enum IpAccessRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Expired = 'expired';
}
