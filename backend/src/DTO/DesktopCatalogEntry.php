<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DesktopCatalogEntry',
    description: 'One selectable model in GET /v1/models/catalog. id is the catalog key service:providerId:tag.',
    required: ['id', 'providerId', 'service', 'name', 'available', 'unavailableReason'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: 'ollama:llama3.2:chat'),
        new OA\Property(property: 'providerId', type: 'string', example: 'llama3.2'),
        new OA\Property(property: 'service', type: 'string', example: 'ollama'),
        new OA\Property(property: 'name', type: 'string', example: 'Llama 3.2'),
        new OA\Property(property: 'available', type: 'boolean', example: true),
        new OA\Property(
            property: 'unavailableReason',
            type: 'string',
            nullable: true,
            enum: ['provider_unavailable', 'not_pulled'],
            example: null,
        ),
        new OA\Property(
            property: 'maxOutput',
            type: 'integer',
            nullable: true,
            description: 'Provider output ceiling from meta.max_output. Omitted when unknown. Desktop caps plain chat at min(maxOutput, 16384), or 8192 when absent.',
            example: 16384,
        ),
    ],
)]
final class DesktopCatalogEntry
{
}
