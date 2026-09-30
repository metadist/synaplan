<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * A model an admin can pick for a Smart Search slot.
 */
#[OA\Schema(
    schema: 'AdminSearchModelOption',
    required: ['id', 'name', 'service', 'available', 'reason'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 76),
        new OA\Property(property: 'name', type: 'string', example: 'gpt-oss-120b'),
        new OA\Property(property: 'service', type: 'string', example: 'Groq'),
        new OA\Property(property: 'available', type: 'boolean', example: true),
        new OA\Property(property: 'reason', type: 'string', enum: ['not_pulled', 'provider_unavailable'], nullable: true, example: null),
    ]
)]
final class AdminSearchModelOption
{
}
