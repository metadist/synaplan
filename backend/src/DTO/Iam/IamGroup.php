<?php

declare(strict_types=1);

namespace App\DTO\Iam;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'IamGroup',
    required: ['id', 'name', 'slug', 'description', 'kind', 'memberCount', 'created', 'updated'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Sales'),
        new OA\Property(property: 'slug', type: 'string', example: 'sales'),
        new OA\Property(property: 'description', type: 'string', example: ''),
        new OA\Property(property: 'kind', type: 'string', enum: ['manual', 'directory'], example: 'manual'),
        new OA\Property(property: 'memberCount', type: 'integer', example: 3),
        new OA\Property(property: 'role', type: 'string', enum: ['member', 'manager'], nullable: true),
        new OA\Property(property: 'created', type: 'integer', format: 'int64'),
        new OA\Property(property: 'updated', type: 'integer', format: 'int64'),
    ]
)]
final class IamGroup
{
}
