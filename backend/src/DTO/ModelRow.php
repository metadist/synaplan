<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * One model row of a capability list in GET /api/v1/config/models.
 */
#[OA\Schema(
    schema: 'ModelRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 324),
        new OA\Property(property: 'service', type: 'string', example: 'Groq'),
        new OA\Property(property: 'name', type: 'string', example: 'Qwen 3.6 27B'),
        new OA\Property(property: 'providerId', type: 'string', example: 'qwen/qwen3.6-27b'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: null),
        new OA\Property(property: 'quality', type: 'number', example: 9),
        new OA\Property(property: 'rating', type: 'number', example: 1),
        new OA\Property(property: 'tag', type: 'string', example: 'CHAT'),
        new OA\Property(property: 'isSystemModel', type: 'boolean', example: false),
        new OA\Property(property: 'features', type: 'array', items: new OA\Items(type: 'string', example: 'reasoning')),
        new OA\Property(property: 'reasoningLevels', type: 'array', nullable: true, items: new OA\Items(type: 'string', example: 'medium'), description: 'Discrete reasoning levels this model accepts, cheapest first. Absent when the model has no level knob and the chat keeps the on/off Thinking control.'),
        new OA\Property(property: 'reasoningEffortDefault', type: 'string', nullable: true, example: 'medium', description: 'Level selected when the person has not chosen one. Present only together with reasoningLevels.'),
        new OA\Property(property: 'available', type: 'boolean', example: true, description: 'False only in the admin includeUnavailable view: the provider has no key/URL, or the Ollama model is not pulled.'),
        new OA\Property(property: 'unavailableReason', type: 'string', nullable: true, enum: ['provider_unavailable', 'not_pulled'], example: null),
        new OA\Property(property: 'priceIn', type: 'number', example: 0.15, description: 'USD per input unit of this model, e.g. 1M tokens for a text model. The image dropdowns badge from capabilityPrices when a row has it.'),
        new OA\Property(property: 'priceOut', type: 'number', example: 0.6, description: 'USD per output unit of this model: 1M tokens for a text model, one image for an image model. The image dropdowns badge from capabilityPrices when a row has it.'),
        new OA\Property(property: 'priceKnown', type: 'boolean', example: true, description: 'False when the model was imported from a listing that published no price. The menu then says "price unknown" instead of "Free".'),
        new OA\Property(property: 'endpoint', type: 'string', nullable: true, example: 'synaplan-demo', description: 'Name of the OpenAI-compatible endpoint that serves this model. Absent for every other provider.'),
    ]
)]
final class ModelRow
{
}
