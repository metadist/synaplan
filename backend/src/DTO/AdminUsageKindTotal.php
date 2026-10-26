<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * What one kind of work (chat, video, agent, …) cost one user in the period.
 */
#[OA\Schema(
    schema: 'AdminUsageKindTotal',
    required: ['kind', 'requests', 'tokens', 'cost'],
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['chat', 'video', 'image', 'audio', 'voice', 'transcription', 'embedding', 'agent', 'compute', 'file', 'internal', 'other'], example: 'chat'),
        new OA\Property(property: 'requests', type: 'integer', example: 412),
        new OA\Property(property: 'tokens', type: 'integer', example: 1830000),
        new OA\Property(property: 'cost', type: 'number', format: 'float', description: 'Provider cost in the instance currency units as recorded (BCOST sum)', example: 4.2131),
    ]
)]
final class AdminUsageKindTotal
{
}
