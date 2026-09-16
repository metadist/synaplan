<?php

declare(strict_types=1);

namespace App\Service\SelfAware;

enum CapabilityState: string
{
    case Available = 'available';
    case NeedsSetup = 'needs_setup';
    case Absent = 'absent';
}
