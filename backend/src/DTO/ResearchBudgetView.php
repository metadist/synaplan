<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['searches', 'sources', 'minutes', 'costCap'],
    properties: [
        new OA\Property(property: 'searches', type: 'integer'),
        new OA\Property(property: 'sources', type: 'integer'),
        new OA\Property(property: 'minutes', type: 'integer'),
        new OA\Property(property: 'costCap', type: 'number', format: 'float'),
    ],
)]
final class ResearchBudgetView
{
}
