<?php

declare(strict_types=1);

namespace App\Enums;

enum AcmeChallengeStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
}
