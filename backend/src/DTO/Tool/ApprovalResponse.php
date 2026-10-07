<?php

declare(strict_types=1);

namespace App\DTO\Tool;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ApprovalResponse',
    required: ['id', 'tool', 'preview', 'status', 'expiresAt', 'created', 'requestedBy'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'tool', type: 'string', example: 'mcp:1:create_ticket'),
        new OA\Property(property: 'sideEffect', type: 'string', enum: ['read', 'write', 'destructive']),
        new OA\Property(property: 'preview', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', example: 'pending'),
        new OA\Property(property: 'expiresAt', type: 'integer'),
        new OA\Property(property: 'created', type: 'integer'),
        new OA\Property(property: 'decidedAt', type: 'integer', nullable: true),
        new OA\Property(property: 'decidedBy', type: 'integer', nullable: true),
        new OA\Property(property: 'decidedByName', type: 'string', nullable: true),
        new OA\Property(
            property: 'resolvedRequest',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'method', type: 'string'),
                new OA\Property(property: 'url', type: 'string'),
                new OA\Property(property: 'headers', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
                new OA\Property(property: 'body', type: 'string', nullable: true),
            ]
        ),
        new OA\Property(property: 'canAlwaysAllow', type: 'boolean'),
        new OA\Property(property: 'requestedBy', ref: new Model(type: ApprovalRequestedBy::class)),
    ]
)]
final class ApprovalResponse
{
}
