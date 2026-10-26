<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * One model request on the admin cost board drill-down: when, what kind,
 * which model, what it cost. Never the prompt or the answer.
 */
#[OA\Schema(
    schema: 'AdminUsageRequest',
    required: ['id', 'at', 'kind', 'action', 'channel', 'provider', 'model', 'tokens', 'promptTokens', 'completionTokens', 'cachedTokens', 'cost', 'latencyMs', 'status', 'estimated', 'chatId', 'agentId'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 99120),
        new OA\Property(property: 'at', type: 'integer', description: 'Unix time of the request', example: 1792997403),
        new OA\Property(property: 'kind', type: 'string', enum: ['chat', 'video', 'image', 'audio', 'voice', 'transcription', 'embedding', 'agent', 'compute', 'file', 'internal', 'other'], example: 'chat'),
        new OA\Property(property: 'action', type: 'string', description: 'The raw usage action as recorded (e.g. MESSAGES)', example: 'MESSAGES'),
        new OA\Property(property: 'channel', type: 'string', description: 'Where the request came in: WEB, WIDGET, WHATSAPP, MESSAGES_API, OPENAI_API, …', example: 'WEB'),
        new OA\Property(property: 'provider', type: 'string', example: 'anthropic'),
        new OA\Property(property: 'model', type: 'string', example: 'claude-sonnet-5'),
        new OA\Property(property: 'tokens', type: 'integer', example: 4210),
        new OA\Property(property: 'promptTokens', type: 'integer', example: 3900),
        new OA\Property(property: 'completionTokens', type: 'integer', example: 310),
        new OA\Property(property: 'cachedTokens', type: 'integer', example: 1200),
        new OA\Property(property: 'cost', type: 'number', format: 'float', example: 0.0213),
        new OA\Property(property: 'latencyMs', type: 'integer', example: 1840),
        new OA\Property(property: 'status', type: 'string', example: 'success'),
        new OA\Property(property: 'estimated', type: 'boolean', description: 'True when the token count was estimated, not reported by the provider', example: false),
        new OA\Property(property: 'chatId', type: 'integer', nullable: true, example: 123),
        new OA\Property(property: 'agentId', type: 'integer', nullable: true, example: 7),
    ]
)]
final class AdminUsageRequest
{
}
