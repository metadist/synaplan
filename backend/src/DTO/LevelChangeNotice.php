<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * Why the level on screen is not the one the administrator just picked,
 * or what remains after the administrator grant is removed.
 */
#[OA\Schema(
    schema: 'LevelChangeNotice',
    required: ['kind', 'level', 'source', 'removed'],
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['kept', 'removed'], example: 'kept'),
        new OA\Property(property: 'level', type: 'string', enum: ['NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'], example: 'BUSINESS'),
        new OA\Property(property: 'source', type: 'string', nullable: true, enum: ['stripe', 'iap', 'code', 'admin', 'directory', 'bootstrap'], example: 'stripe'),
        new OA\Property(property: 'removed', type: 'string', nullable: true, enum: ['PRO', 'TEAM', 'BUSINESS', 'ADMIN'], example: 'TEAM'),
    ]
)]
final class LevelChangeNotice
{
}
