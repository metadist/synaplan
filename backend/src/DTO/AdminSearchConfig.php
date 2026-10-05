<?php

declare(strict_types=1);

namespace App\DTO;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Read model of the Smart Search card on AI infrastructure.
 */
#[OA\Schema(
    schema: 'AdminSearchConfig',
    required: ['success', 'ai', 'embed', 'aiEnabled', 'aiAvailable', 'index', 'activeRun', 'otherRunActive', 'latestRun'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'ai', ref: new Model(type: AdminSearchModelSlot::class)),
        new OA\Property(property: 'embed', ref: new Model(type: AdminSearchModelSlot::class)),
        new OA\Property(property: 'aiEnabled', type: 'boolean', description: 'FEATURE_SEARCH_AI_ENABLED', example: true),
        new OA\Property(property: 'aiAvailable', type: 'boolean', description: 'Enabled and the AI model can answer now', example: true),
        new OA\Property(
            property: 'index',
            required: ['rows', 'embeddedRows', 'semanticAvailable'],
            properties: [
                new OA\Property(property: 'rows', type: 'integer', example: 640),
                new OA\Property(property: 'embeddedRows', type: 'integer', description: 'Rows with a vector from the effective embedding model', example: 640),
                new OA\Property(property: 'semanticAvailable', type: 'boolean', example: true),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'activeRun', ref: new Model(type: AdminSearchRun::class), nullable: true),
        new OA\Property(property: 'otherRunActive', type: 'boolean', description: 'A documents or memories reindex is running; the embedding model cannot change until it ends', example: false),
        new OA\Property(property: 'latestRun', ref: new Model(type: AdminSearchRun::class), nullable: true),
    ]
)]
final class AdminSearchConfig
{
}
