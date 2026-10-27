<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['started', 'id', 'sentence'],
    properties: [
        new OA\Property(property: 'started', type: 'boolean'),
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'sentence', type: 'string'),
    ],
)]
final class ResearchSampleView
{
}
