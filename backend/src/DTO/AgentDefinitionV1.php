<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * The published OpenAPI shape of an `agent.v1` document.
 *
 * This is the one place the wire format is described: every endpoint that
 * carries a draft or a version definition references this component, and
 * the frontend types are generated from it. The server-side enforcement
 * lives in {@see \App\Service\Agent\Definition\AgentDefinitionValidator};
 * {@see \App\Tests\Unit\Service\Agent\AgentDefinitionSchemaParityTest}
 * fails when the two drift apart.
 */
#[OA\Schema(
    schema: 'AgentDefinitionV1',
    description: 'A complete agent.v1 document. Unknown keys are rejected; missing sections are filled with defaults on write.',
    required: ['schema', 'models', 'knowledge', 'tools', 'skills', 'parameters', 'behaviour', 'triggers'],
    properties: [
        new OA\Property(property: 'schema', type: 'string', enum: ['agent.v1']),
        new OA\Property(
            property: 'models',
            type: 'object',
            description: 'Catalog keys "service:providerId:tag" per capability; null falls back to the user default.',
            required: ['chat', 'vision', 'vectorize'],
            properties: [
                new OA\Property(property: 'chat', type: 'string', nullable: true, example: 'anthropic:claude-sonnet-4:chat'),
                new OA\Property(property: 'vision', type: 'string', nullable: true),
                new OA\Property(property: 'vectorize', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'knowledge',
            type: 'object',
            required: ['ownFolder', 'folders', 'includeUserFiles', 'ragLimit', 'ragMinScore'],
            properties: [
                new OA\Property(property: 'ownFolder', type: 'boolean', description: 'Search the assistant\'s own TASKPROMPT folder.'),
                new OA\Property(property: 'folders', type: 'array', items: new OA\Items(type: 'string', example: '4:contracts'), description: 'Additional knowledge folders as "ownerId:groupKey".'),
                new OA\Property(property: 'includeUserFiles', type: 'boolean', description: 'Also search the talking user\'s own files (default false).'),
                new OA\Property(property: 'ragLimit', type: 'integer', minimum: 1, maximum: 50),
                new OA\Property(property: 'ragMinScore', type: 'number', format: 'float', minimum: 0, maximum: 1),
            ],
        ),
        new OA\Property(
            property: 'tools',
            type: 'object',
            required: ['internet', 'files', 'mcpServers', 'allow', 'deny'],
            properties: [
                new OA\Property(property: 'internet', type: 'boolean'),
                new OA\Property(property: 'files', type: 'boolean'),
                new OA\Property(property: 'mcpServers', type: 'array', items: new OA\Items(type: 'integer')),
                new OA\Property(property: 'allow', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'deny', type: 'array', items: new OA\Items(type: 'string')),
            ],
        ),
        new OA\Property(
            property: 'skills',
            type: 'object',
            required: ['allow', 'deny'],
            properties: [
                new OA\Property(property: 'allow', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'deny', type: 'array', items: new OA\Items(type: 'string')),
            ],
        ),
        new OA\Property(
            property: 'parameters',
            type: 'object',
            required: ['temperature', 'maxTokens', 'language', 'responseSchema'],
            properties: [
                new OA\Property(property: 'temperature', type: 'number', format: 'float', minimum: 0, maximum: 2),
                new OA\Property(property: 'maxTokens', type: 'integer', minimum: 1),
                new OA\Property(property: 'language', type: 'string', example: 'auto'),
                new OA\Property(property: 'responseSchema', type: 'object', nullable: true, additionalProperties: true, description: 'Optional JSON schema the answer must satisfy.'),
            ],
        ),
        new OA\Property(
            property: 'behaviour',
            type: 'object',
            required: ['greeting', 'starterPrompts', 'memory'],
            properties: [
                new OA\Property(property: 'greeting', type: 'string'),
                new OA\Property(property: 'starterPrompts', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'memory', type: 'string', enum: ['user']),
            ],
        ),
        new OA\Property(
            property: 'triggers',
            type: 'object',
            required: ['events', 'schedules'],
            properties: [
                new OA\Property(
                    property: 'events',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'string'),
                            new OA\Property(property: 'kind', type: 'string', enum: ['mail', 'whatsapp', 'widget', 'api', 'mcp', 'desktop', 'webhook']),
                            new OA\Property(property: 'mailbox', type: 'string'),
                            new OA\Property(property: 'rule', type: 'string'),
                            new OA\Property(property: 'instruction', type: 'string'),
                            new OA\Property(property: 'widget', type: 'string'),
                            new OA\Property(property: 'widgetDefaults', type: 'object', additionalProperties: true),
                            new OA\Property(property: 'number', type: 'string'),
                            new OA\Property(property: 'enabled', type: 'boolean'),
                            new OA\Property(property: 'department', type: 'string'),
                        ],
                    ),
                ),
                new OA\Property(
                    property: 'schedules',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'string'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'every', type: 'string'),
                            new OA\Property(property: 'cron', type: 'string'),
                            new OA\Property(property: 'tz', type: 'string'),
                            new OA\Property(property: 'instruction', type: 'string'),
                            new OA\Property(property: 'allowUnattended', type: 'boolean'),
                            new OA\Property(property: 'enabled', type: 'boolean'),
                        ],
                    ),
                ),
            ],
        ),
    ],
)]
final class AgentDefinitionV1
{
}
