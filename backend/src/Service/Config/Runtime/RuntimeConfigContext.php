<?php

declare(strict_types=1);

namespace App\Service\Config\Runtime;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * What a runtime-config contributor is allowed to see: the request and the
 * signed-in user, when there is one.
 */
final readonly class RuntimeConfigContext
{
    public function __construct(
        public Request $request,
        public ?User $user,
    ) {
    }
}
