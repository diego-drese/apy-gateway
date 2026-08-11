<?php

declare(strict_types=1);

namespace App\Enums;

enum SslCertificateProvider: string
{
    case LetsEncrypt = 'letsencrypt';
    case Manual = 'manual';
}
