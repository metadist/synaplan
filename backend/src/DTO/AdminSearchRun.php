<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * A reindex run that moved the Smart Search index onto another embedding model.
 */
#[OA\Schema(
    schema: 'AdminSearchRun',
    required: ['id', 'status', 'fromModelId', 'toModelId', 'rowsTotal', 'rowsProcessed', 'rowsFailed', 'finishedAt'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'status', type: 'string', enum: ['queued', 'running', 'completed', 'failed', 'cancelled'], example: 'running'),
        new OA\Property(property: 'fromModelId', type: 'integer', example: 13),
        new OA\Property(property: 'toModelId', type: 'integer', example: 88),
        new OA\Property(property: 'rowsTotal', type: 'integer', nullable: true, example: 640),
        new OA\Property(property: 'rowsProcessed', type: 'integer', example: 320),
        new OA\Property(property: 'rowsFailed', type: 'integer', example: 0),
        new OA\Property(property: 'finishedAt', type: 'integer', nullable: true, example: null),
    ]
)]
final class AdminSearchRun
{
}
