<?php

declare(strict_types=1);

namespace App\Module\Contract;

/**
 * Release class of a module's files under .github/mobile-impact-policy.json.
 * No module is ever `store-required`: native concerns are not optional features.
 */
enum MobileClass: string
{
    case BackendOnly = 'backend-only';
    case OtaCandidate = 'ota-candidate';
}
