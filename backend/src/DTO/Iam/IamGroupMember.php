<?php

declare(strict_types=1);

namespace App\DTO\Iam;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'IamGroupMember',
    required: ['userId', 'email', 'role', 'source', 'created'],
    properties: [
        new OA\Property(property: 'userId', type: 'integer', example: 4),
        new OA\Property(property: 'email', type: 'string', example: 'ada@example.com'),
        new OA\Property(property: 'role', type: 'string', enum: ['member', 'manager'], example: 'member'),
        new OA\Property(property: 'source', type: 'string', enum: ['manual', 'directory'], example: 'manual'),
        new OA\Property(property: 'created', type: 'integer', format: 'int64'),
    ]
)]
final class IamGroupMember
{
}
