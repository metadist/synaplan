<?php

declare(strict_types=1);

namespace App\DTO;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One Smart Search model slot: the admin choice and what it falls back to.
 */
#[OA\Schema(
    schema: 'AdminSearchModelSlot',
    required: ['selectedModelId', 'inheritedModelId', 'inheritedModelName', 'effectiveModelId', 'effectiveModelName', 'options'],
    properties: [
        new OA\Property(property: 'selectedModelId', type: 'integer', nullable: true, description: 'Admin choice; null while the slot inherits', example: null),
        new OA\Property(property: 'inheritedModelId', type: 'integer', nullable: true, description: 'AI: the tools model; embed: the instance embedding model', example: 76),
        new OA\Property(property: 'inheritedModelName', type: 'string', nullable: true, description: 'Display name, also for models not offered as options', example: 'gpt-oss-120b (Groq)'),
        new OA\Property(property: 'effectiveModelId', type: 'integer', nullable: true, example: 76),
        new OA\Property(property: 'effectiveModelName', type: 'string', nullable: true, example: 'gpt-oss-120b (Groq)'),
        new OA\Property(property: 'options', type: 'array', items: new OA\Items(ref: new Model(type: AdminSearchModelOption::class))),
    ]
)]
final class AdminSearchModelSlot
{
}
