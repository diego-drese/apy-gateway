<?php

declare(strict_types=1);

namespace App\Enums;

enum SslCertificateType: string
{
    case Acme = 'acme';
    case Uploaded = 'uploaded';
}
