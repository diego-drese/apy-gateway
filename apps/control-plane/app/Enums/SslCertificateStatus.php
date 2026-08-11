<?php

declare(strict_types=1);

namespace App\Enums;

enum SslCertificateStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Expiring = 'expiring';
    case Expired = 'expired';
    case Error = 'error';
}
