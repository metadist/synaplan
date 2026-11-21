<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * The person's cost limit for one long chat answer, as the settings page
 * shows it. Money is what the person is billed, in US dollars.
 */
#[OA\Schema(
    schema: 'ChatRunLimit',
    required: ['enabled', 'percent', 'default', 'choices', 'monthlyBudget', 'perRun'],
    properties: [
        new OA\Property(property: 'enabled', type: 'boolean', example: true, description: 'Long chat answers are on for this install'),
        new OA\Property(property: 'percent', type: 'integer', example: 10, description: 'The share of the monthly budget one answer may spend'),
        new OA\Property(property: 'default', type: 'integer', example: 10, description: 'The share used until the person chooses one'),
        new OA\Property(property: 'choices', type: 'array', items: new OA\Items(type: 'integer'), example: [5, 10, 20, 25, 50, 100]),
        new OA\Property(property: 'monthlyBudget', type: 'string', nullable: true, example: '20.00', description: 'This period\'s budget with top-ups; null when the plan has none'),
        new OA\Property(property: 'perRun', type: 'string', nullable: true, example: '2.00', description: 'What one answer may cost with the chosen share; null without a monthly budget'),
    ],
    type: 'object'
)]
final class ChatRunLimit
{
}
