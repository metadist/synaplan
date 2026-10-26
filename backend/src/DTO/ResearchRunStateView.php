<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ResearchRunStateView',
    description: 'One research run as its owner reads it (research_protocol 1). Budget pairs are [used, limit].',
    required: ['id', 'research_protocol', 'status', 'depth', 'question', 'stop_requested', 'write_requested', 'queued_since', 'questions', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: 'rr_0123456789abcdef'),
        new OA\Property(property: 'research_protocol', type: 'integer', example: 1),
        new OA\Property(property: 'status', type: 'string', enum: ['briefing', 'waiting_answer', 'planning', 'waiting_plan', 'gathering', 'writing', 'checking', 'complete', 'stopped', 'failed'], example: 'gathering'),
        new OA\Property(property: 'depth', type: 'string', enum: ['quick', 'standard', 'thorough'], example: 'standard'),
        new OA\Property(property: 'question', type: 'string', example: 'Which suppliers deliver X in Germany?'),
        new OA\Property(property: 'parent', type: 'string', nullable: true, example: null),
        new OA\Property(property: 'stop_requested', type: 'boolean', example: false),
        new OA\Property(property: 'write_requested', type: 'boolean', description: 'The person asked to write the report from what was already found.', example: false),
        new OA\Property(property: 'queued_since', type: 'integer', nullable: true, description: 'Seconds the next step has waited for a worker, from 20 seconds on. Null while a worker has it, while the run waits for the person, and once it has finished.', example: null),
        new OA\Property(
            property: 'questions',
            description: 'The questions the run is waiting on, with what was already answered or skipped. Empty when the run is not waiting for an answer.',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                required: ['id', 'text', 'options', 'allow_text', 'answer', 'skipped'],
                properties: [
                    new OA\Property(property: 'id', type: 'string', example: 'q1'),
                    new OA\Property(property: 'text', type: 'string', example: 'Which region should the research cover?'),
                    new OA\Property(property: 'options', type: 'array', items: new OA\Items(type: 'string'), example: ['EU', 'US']),
                    new OA\Property(property: 'allow_text', type: 'boolean', example: true),
                    new OA\Property(property: 'answer', type: 'string', nullable: true, example: null),
                    new OA\Property(property: 'skipped', type: 'boolean', example: false),
                ],
            ),
        ),
        new OA\Property(property: 'brief', type: 'object', nullable: true),
        new OA\Property(property: 'plan', type: 'object', nullable: true),
        new OA\Property(property: 'sources', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'evidence', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(
            property: 'report',
            type: 'object',
            nullable: true,
            required: ['markdown', 'version'],
            properties: [
                new OA\Property(property: 'markdown', type: 'string'),
                new OA\Property(property: 'version', type: 'integer', example: 1),
            ],
        ),
        new OA\Property(
            property: 'check',
            description: 'Fact-check counts once the report was checked; null before that.',
            type: 'object',
            nullable: true,
            required: ['statements', 'supported', 'removed'],
            properties: [
                new OA\Property(property: 'statements', type: 'integer', example: 12),
                new OA\Property(property: 'supported', type: 'integer', example: 10),
                new OA\Property(property: 'removed', type: 'integer', example: 2),
            ],
        ),
        new OA\Property(
            property: 'outcome',
            type: 'object',
            nullable: true,
            required: ['code', 'params', 'text'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'stopped_by_user'),
                new OA\Property(property: 'params', type: 'object'),
                new OA\Property(property: 'text', type: 'string', example: 'Stopped. What was found so far is kept.'),
            ],
        ),
        new OA\Property(property: 'models', type: 'object'),
        new OA\Property(
            property: 'budget',
            type: 'object',
            properties: [
                new OA\Property(property: 'searches', type: 'array', items: new OA\Items(type: 'integer')),
                new OA\Property(property: 'sources_read', type: 'array', items: new OA\Items(type: 'integer')),
                new OA\Property(property: 'rounds', type: 'array', items: new OA\Items(type: 'integer')),
                new OA\Property(property: 'seconds', type: 'array', items: new OA\Items(type: 'integer')),
            ],
        ),
        new OA\Property(
            property: 'cost',
            type: 'object',
            properties: [
                new OA\Property(property: 'estimate', type: 'number', example: 0.12),
                new OA\Property(property: 'spent', type: 'number', example: 0.04),
                new OA\Property(property: 'currency', type: 'string', example: 'USD'),
            ],
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
final class ResearchRunStateView
{
}
