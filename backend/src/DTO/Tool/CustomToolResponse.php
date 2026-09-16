<?php

declare(strict_types=1);

namespace App\DTO\Tool;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomToolResponse',
    required: ['id', 'name', 'title', 'type', 'sideEffect', 'spec', 'enabled'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'create_ticket'),
        new OA\Property(property: 'title', type: 'string', example: 'Create ticket'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'type', type: 'string', example: 'http'),
        new OA\Property(property: 'sideEffect', type: 'string', enum: ['read', 'write', 'destructive']),
        new OA\Property(property: 'spec', type: 'object', additionalProperties: true),
        new OA\Property(property: 'inputSchema', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'credentialId', type: 'integer', nullable: true),
        new OA\Property(property: 'enabled', type: 'boolean'),
        new OA\Property(property: 'sourceRef', type: 'string', nullable: true),
        new OA\Property(property: 'created', type: 'integer'),
        new OA\Property(property: 'updated', type: 'integer'),
        new OA\Property(property: 'registryName', type: 'string', example: 'custom:create_ticket'),
    ]
)]
final class CustomToolResponse
{
}
