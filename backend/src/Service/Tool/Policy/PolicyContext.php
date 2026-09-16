<?php

declare(strict_types=1);

namespace App\Service\Tool\Policy;

enum PolicyContext: string
{
    case Interactive = 'interactive';
    case Unattended = 'unattended';
}
