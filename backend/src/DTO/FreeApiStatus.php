<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * The Synaplan Demo as the chat and the admin see it. Never carries the key.
 */
#[OA\Schema(
    schema: 'FreeApiStatus',
    required: ['available', 'active', 'canManage', 'server', 'expiresAt', 'daysLeft', 'activatedAt', 'activatedBy', 'installId', 'limits', 'today', 'refusal'],
    properties: [
        new OA\Property(property: 'available', type: 'boolean', example: true, description: 'The module is on and names a usable Demo server'),
        new OA\Property(property: 'active', type: 'boolean', example: true, description: 'The Demo answers chat on this install'),
        new OA\Property(property: 'canManage', type: 'boolean', example: true, description: 'The caller may start and leave it (admins)'),
        new OA\Property(property: 'server', type: 'string', nullable: true, example: 'web.synaplan.com', description: 'Host that answers the Demo messages'),
        new OA\Property(property: 'expiresAt', type: 'integer', nullable: true, example: 1761868800, description: 'Unix time the Demo ends; null while off'),
        new OA\Property(property: 'daysLeft', type: 'integer', nullable: true, example: 12, description: 'Whole days until it ends, rounded up; null while off'),
        new OA\Property(property: 'activatedAt', type: 'integer', nullable: true, example: 1760659200, description: 'Unix time it was started; null while off'),
        new OA\Property(property: 'activatedBy', type: 'string', nullable: true, example: 'admin@synaplan.com', description: 'E-mail of the admin who started it. Admins only; null from the console'),
        new OA\Property(property: 'installId', type: 'string', nullable: true, example: '9f3c2a10-5b7e-4c1d-8a2f-0e6b4d3c2a19', description: 'This install as the Demo server knows it. Admins only'),
        new OA\Property(
            property: 'limits',
            required: ['messagesPerDay', 'imagesPerDay'],
            properties: [
                new OA\Property(property: 'messagesPerDay', type: 'integer', nullable: true, example: 40),
                new OA\Property(property: 'imagesPerDay', type: 'integer', nullable: true, example: 5),
            ],
            type: 'object',
            description: 'Daily allowance at the start; null values while off'
        ),
        new OA\Property(
            property: 'today',
            required: ['messagesRemaining', 'messagesLimit', 'imagesRemaining', 'imagesLimit', 'resetsAt'],
            properties: [
                new OA\Property(property: 'messagesRemaining', type: 'integer', example: 27),
                new OA\Property(property: 'messagesLimit', type: 'integer', example: 40),
                new OA\Property(property: 'imagesRemaining', type: 'integer', example: 3),
                new OA\Property(property: 'imagesLimit', type: 'integer', example: 5),
                new OA\Property(property: 'resetsAt', type: 'integer', nullable: true, example: 1760738400, description: 'Unix time the allowance resets (midnight, German time)'),
            ],
            type: 'object',
            nullable: true,
            description: "Today's use as the Demo server reports it; null while off or when the server did not answer"
        ),
        new OA\Property(property: 'refusal', type: 'string', nullable: true, example: null, description: 'freeapi_* code when the Demo server refused the status call (for example freeapi_paused, freeapi_expired)'),
    ]
)]
final class FreeApiStatus
{
}
