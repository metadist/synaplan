<?php

declare(strict_types=1);

namespace App\Bundle;

enum BundleScope: string
{
    case User = 'user';
    case Instance = 'instance';
}
