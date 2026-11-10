<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * Why a connected cloud's call for a person was refused. The descriptions
 * are shared by every person route a cloud calls.
 */
#[OA\Schema(
    schema: 'CloudCallerError',
    required: ['error'],
    properties: [
        new OA\Property(property: 'error', type: 'string', example: 'platform_disconnected', description: 'unauthorized, platform_disconnected, instance_pending, platform_ambiguous, unknown_delivery, already_claimed, expired, rate_limited, …'),
    ]
)]
final class CloudCallerError
{
    public const UNAUTHORIZED = 'Not signed in (unauthorized)';
    public const FORBIDDEN = 'The credential belongs to no connected cloud (platform_disconnected), or the cloud waits for approval (instance_pending)';
    public const AMBIGUOUS = 'More than one cloud is registered for this login (platform_ambiguous)';
}
