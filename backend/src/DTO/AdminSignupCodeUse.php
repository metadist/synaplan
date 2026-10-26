<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * One account that signed up with a sign-up code.
 */
#[OA\Schema(
    schema: 'AdminSignupCodeUse',
    required: ['id', 'userId', 'email', 'level', 'usedAt'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 3),
        new OA\Property(property: 'userId', type: 'integer', example: 42),
        new OA\Property(property: 'email', type: 'string', nullable: true, description: 'Null when the account no longer exists', example: 'ada@example.com'),
        new OA\Property(property: 'level', type: 'string', description: 'The tier the code granted at that moment', example: 'TEAM'),
        new OA\Property(property: 'usedAt', type: 'integer', example: 1761400000),
    ]
)]
final class AdminSignupCodeUse
{
}
