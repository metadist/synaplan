<?php

declare(strict_types=1);

namespace App\DTO\Tool;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OpenApiOperationPreview',
    required: ['operationId', 'method', 'path', 'sideEffect'],
    properties: [
        new OA\Property(property: 'operationId', type: 'string', example: 'createTicket'),
        new OA\Property(property: 'summary', type: 'string'),
        new OA\Property(property: 'method', type: 'string', example: 'POST'),
        new OA\Property(property: 'path', type: 'string', example: '/tickets'),
        new OA\Property(property: 'sideEffect', type: 'string', enum: ['read', 'write', 'destructive']),
        new OA\Property(property: 'inputSchema', type: 'object', additionalProperties: true),
        new OA\Property(property: 'sourceRef', type: 'string'),
    ]
)]
final class OpenApiOperationPreview
{
}
