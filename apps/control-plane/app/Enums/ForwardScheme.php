<?php

declare(strict_types=1);

namespace App\Enums;

enum ForwardScheme: string
{
    case Http = 'http';
    case Https = 'https';
}
