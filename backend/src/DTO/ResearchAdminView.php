<?php

declare(strict_types=1);

namespace App\DTO;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ResearchAdminView',
    required: ['enabled', 'envLocked', 'readingModelId', 'checkingModelId', 'models', 'limits', 'budgets', 'sample'],
    properties: [
        new OA\Property(property: 'enabled', type: 'boolean'),
        new OA\Property(property: 'envLocked', type: 'boolean'),
        new OA\Property(property: 'readingModelId', type: 'integer', nullable: true),
        new OA\Property(property: 'checkingModelId', type: 'integer', nullable: true),
        new OA\Property(property: 'models', type: 'array', items: new OA\Items(type: 'object', required: ['id', 'name', 'available', 'reason'], properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'available', type: 'boolean'),
            new OA\Property(property: 'reason', type: 'string', nullable: true),
        ])),
        new OA\Property(property: 'limits', type: 'object', required: ['maxActiveRuns', 'maxActiveThorough'], properties: [
            new OA\Property(property: 'maxActiveRuns', type: 'integer'),
            new OA\Property(property: 'maxActiveThorough', type: 'integer'),
        ]),
        new OA\Property(property: 'budgets', type: 'object', required: ['quick', 'standard', 'thorough'], properties: [
            new OA\Property(property: 'quick', ref: new Model(type: ResearchBudgetView::class)),
            new OA\Property(property: 'standard', ref: new Model(type: ResearchBudgetView::class)),
            new OA\Property(property: 'thorough', ref: new Model(type: ResearchBudgetView::class)),
        ]),
        new OA\Property(property: 'sample', description: 'The newest sample run, or null when there is none', type: 'object', nullable: true, required: ['id', 'status', 'sentence'], properties: [
            new OA\Property(property: 'id', type: 'string'),
            new OA\Property(property: 'status', type: 'string', enum: ['running', 'done']),
            new OA\Property(property: 'sentence', description: 'The outcome in one sentence once the sample is done', type: 'string', nullable: true),
        ]),
    ],
)]
final class ResearchAdminView
{
}
