<?php

declare(strict_types=1);

namespace App\DTO\Tool;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ApprovalRequestedBy',
    required: ['kind'],
    properties: [
        new OA\Property(property: 'kind', type: 'string', example: 'chat'),
        new OA\Property(property: 'chatId', type: 'integer', nullable: true),
        new OA\Property(property: 'messageId', type: 'integer', nullable: true),
        new OA\Property(property: 'taskId', type: 'integer', nullable: true),
        new OA\Property(property: 'runId', type: 'integer', nullable: true),
        new OA\Property(property: 'nodeId', type: 'string', nullable: true),
    ]
)]
final class ApprovalRequestedBy
{
}
