<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * A Synaplan Demo action that did not happen. The UI shows its own sentence per code.
 */
#[OA\Schema(
    schema: 'FreeApiError',
    required: ['error', 'message'],
    properties: [
        new OA\Property(property: 'error', type: 'string', example: 'freeapi_chat_ready', description: 'freeapi_* code: freeapi_consent_required, freeapi_already_active, freeapi_own_endpoint, freeapi_chat_ready, freeapi_unreachable, freeapi_invalid_response, freeapi_throttled, freeapi_full_today, freeapi_paused, freeapi_blocked, freeapi_upgrade_required, …'),
        new OA\Property(property: 'message', type: 'string', example: 'Chat already works on this server with your own AI provider, so the Synaplan Demo is not needed.', description: 'Plain English for logs and the console'),
    ]
)]
final class FreeApiError
{
}
