<?php

declare(strict_types=1);

namespace App\DTO\Mcp;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'McpToolPermissionListResponse',
    required: ['success', 'can_edit', 'notice', 'tools'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'error', type: 'string', nullable: true, example: null),
        new OA\Property(property: 'can_edit', type: 'boolean', example: true),
        new OA\Property(property: 'notice', type: 'boolean', example: false, description: 'True while the one-time notice (writes no longer ask first) is open for the owner'),
        new OA\Property(property: 'tools', type: 'array', items: new OA\Items(
            required: ['name', 'title', 'description', 'input_count', 'input_names', 'category', 'outcome', 'default_outcome', 'hinted', 'is_new', 'locked_reason'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'update-page'),
                new OA\Property(property: 'title', type: 'string', example: 'update-page'),
                new OA\Property(property: 'description', type: 'string', example: 'Change a page'),
                new OA\Property(property: 'input_count', type: 'integer', example: 3),
                new OA\Property(property: 'input_names', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'category', type: 'string', enum: ['read', 'write', 'destructive', 'unavailable'], example: 'write'),
                new OA\Property(property: 'outcome', type: 'string', enum: ['auto', 'approve', 'block'], example: 'auto'),
                new OA\Property(property: 'default_outcome', type: 'string', enum: ['auto', 'approve', 'block'], example: 'auto'),
                new OA\Property(property: 'hinted', type: 'boolean', example: true),
                new OA\Property(property: 'is_new', type: 'boolean', example: false),
                new OA\Property(property: 'locked_reason', type: 'string', nullable: true, enum: ['group_policy', 'shared', 'unavailable'], example: null),
            ],
            type: 'object'
        )),
    ],
    type: 'object'
)]
final class McpToolPermissionListResponse
{
}
