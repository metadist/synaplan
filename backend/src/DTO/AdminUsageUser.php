<?php

declare(strict_types=1);

namespace App\DTO;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One row of the admin cost board: a user and what their model use cost in
 * the selected period, split by kind. No message content, ever.
 */
#[OA\Schema(
    schema: 'AdminUsageUser',
    required: ['userId', 'email', 'displayName', 'level', 'accountStatus', 'requests', 'tokens', 'promptTokens', 'completionTokens', 'cost', 'lastActiveAt', 'byKind'],
    properties: [
        new OA\Property(property: 'userId', type: 'integer', example: 42),
        new OA\Property(property: 'email', type: 'string', example: 'ada@example.com'),
        new OA\Property(property: 'displayName', type: 'string', description: 'Stored name, or the email when none is stored', example: 'Ada Example'),
        new OA\Property(property: 'level', type: 'string', example: 'PRO'),
        new OA\Property(property: 'accountStatus', type: 'string', example: 'active'),
        new OA\Property(property: 'requests', type: 'integer', example: 531),
        new OA\Property(property: 'tokens', type: 'integer', example: 2210000),
        new OA\Property(property: 'promptTokens', type: 'integer', example: 1900000),
        new OA\Property(property: 'completionTokens', type: 'integer', example: 310000),
        new OA\Property(property: 'cost', type: 'number', format: 'float', description: 'Provider cost (BCOST sum) in the period', example: 5.1204),
        new OA\Property(property: 'lastActiveAt', type: 'integer', description: 'Unix time of the newest request in the period', example: 1792997403),
        new OA\Property(property: 'byKind', type: 'array', items: new OA\Items(ref: new Model(type: AdminUsageKindTotal::class))),
    ]
)]
final class AdminUsageUser
{
}
