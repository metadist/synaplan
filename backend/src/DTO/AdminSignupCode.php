<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * One sign-up code as the administrator sees it. Never the code itself: that
 * leaves the server once, in the create response.
 */
#[OA\Schema(
    schema: 'AdminSignupCode',
    required: ['id', 'prefix', 'label', 'level', 'maxUses', 'uses', 'expiresAt', 'active', 'state', 'createdAt', 'createdById', 'createdByEmail'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 7),
        new OA\Property(property: 'prefix', type: 'string', description: 'The first four characters, to recognise the code', example: 'R7KQ'),
        new OA\Property(property: 'label', type: 'string', example: 'Pilot customers'),
        new OA\Property(property: 'level', type: 'string', enum: ['NEW', 'PRO', 'TEAM', 'BUSINESS'], description: 'The tier an account gets when it signs up with this code', example: 'TEAM'),
        new OA\Property(property: 'maxUses', type: 'integer', nullable: true, description: 'Null: no limit', example: 5),
        new OA\Property(property: 'uses', type: 'integer', example: 2),
        new OA\Property(property: 'expiresAt', type: 'integer', nullable: true, description: 'Unix time the code stops working; null: never', example: 1789000000),
        new OA\Property(property: 'active', type: 'boolean', description: 'False once revoked', example: true),
        new OA\Property(property: 'state', type: 'string', enum: ['active', 'expired', 'used_up', 'revoked'], example: 'active'),
        new OA\Property(property: 'createdAt', type: 'integer', example: 1761400000),
        new OA\Property(property: 'createdById', type: 'integer', example: 1),
        new OA\Property(property: 'createdByEmail', type: 'string', nullable: true, description: 'Null when that account no longer exists', example: 'admin@example.com'),
    ]
)]
final class AdminSignupCode
{
}
