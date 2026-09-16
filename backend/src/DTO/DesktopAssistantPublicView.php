<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DesktopAssistantPublicView',
    description: 'Reader publicView for GET /v1/assistants. Never includes the draft JSON. models.* are published (or owner-visible) catalog keys.',
    required: ['id', 'name', 'models'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'slug', type: 'string', example: 'contract-review'),
        new OA\Property(property: 'name', type: 'string', example: 'Contract review'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Reviews NDAs'),
        new OA\Property(property: 'icon', type: 'string', example: ''),
        new OA\Property(property: 'status', type: 'string', example: 'published'),
        new OA\Property(property: 'promptId', type: 'integer', nullable: true, example: 42),
        new OA\Property(property: 'parentId', type: 'integer', nullable: true),
        new OA\Property(property: 'source', type: 'string', example: 'manual'),
        new OA\Property(property: 'routable', type: 'boolean', example: false),
        new OA\Property(property: 'publishedVersionId', type: 'integer', nullable: true, example: 4),
        new OA\Property(property: 'createdAt', type: 'integer', example: 1757232000),
        new OA\Property(property: 'updatedAt', type: 'integer', example: 1757232000),
        new OA\Property(
            property: 'models',
            type: 'object',
            required: ['chat', 'vision', 'vectorize'],
            properties: [
                new OA\Property(property: 'chat', type: 'string', nullable: true, example: 'ollama:llama3.2:chat'),
                new OA\Property(property: 'vision', type: 'string', nullable: true, example: null),
                new OA\Property(property: 'vectorize', type: 'string', nullable: true, example: 'ollama:bge-m3:vectorize'),
            ],
        ),
    ],
)]
final class DesktopAssistantPublicView
{
}
