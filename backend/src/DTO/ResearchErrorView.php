<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ResearchErrorView',
    description: 'A research request that was refused. `message` is a sentence the person can read; `field` names what to correct.',
    required: ['error'],
    properties: [
        new OA\Property(
            property: 'error',
            type: 'object',
            required: ['code', 'field', 'message'],
            properties: [
                new OA\Property(property: 'code', type: 'string', enum: ['invalid', 'not_found', 'not_waiting', 'too_many', 'conflict', 'unavailable', 'unauthorized'], example: 'invalid'),
                new OA\Property(property: 'field', type: 'string', nullable: true, example: 'question'),
                new OA\Property(property: 'message', type: 'string', example: 'The question needs between 1 and 4000 characters.'),
            ],
        ),
    ],
)]
final class ResearchErrorView
{
}
