<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * One reason an account has its level. Store purchase tokens are never
 * included; a reference is a Stripe subscription id or a sign-up code id.
 */
#[OA\Schema(
    schema: 'LevelGrantView',
    required: ['source', 'level', 'created', 'reference'],
    properties: [
        new OA\Property(property: 'source', type: 'string', enum: ['stripe', 'iap', 'code', 'admin', 'directory', 'bootstrap'], example: 'stripe'),
        new OA\Property(property: 'level', type: 'string', enum: ['PRO', 'TEAM', 'BUSINESS', 'ADMIN'], example: 'PRO'),
        new OA\Property(property: 'created', type: 'string', format: 'date-time', example: '2026-11-10T12:00:00+00:00'),
        new OA\Property(property: 'reference', type: 'string', nullable: true, example: 'sub_123', description: 'Stripe subscription id or sign-up code id. Store tokens are never returned.'),
    ]
)]
final class LevelGrantView
{
}
