<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * One followed Notion page, as the Notion app lists it.
 */
#[OA\Schema(
    schema: 'NotionWatchState',
    required: ['id', 'page_url', 'title', 'action', 'folder', 'task_id', 'task_name', 'file_id', 'enabled', 'checked_at', 'changed_at', 'last_error'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 5),
        new OA\Property(property: 'page_url', type: 'string', example: 'https://www.notion.so/0123456789abcdef0123456789abcdef'),
        new OA\Property(property: 'title', type: 'string', nullable: true, description: 'Null until the first check could read the page', example: 'Roadmap'),
        new OA\Property(property: 'action', type: 'string', enum: ['searchable', 'task'], example: 'searchable'),
        new OA\Property(property: 'folder', type: 'string', nullable: true, example: 'Notion'),
        new OA\Property(property: 'task_id', type: 'integer', nullable: true, example: null),
        new OA\Property(property: 'task_name', type: 'string', nullable: true, example: null),
        new OA\Property(property: 'file_id', type: 'integer', nullable: true, description: 'The searchable copy', example: 42),
        new OA\Property(property: 'enabled', type: 'boolean', example: true),
        new OA\Property(property: 'checked_at', type: 'integer', nullable: true, example: 1793091600),
        new OA\Property(property: 'changed_at', type: 'integer', nullable: true, example: null),
        new OA\Property(property: 'last_error', type: 'string', nullable: true, description: 'Why the last check failed, in plain words', example: null),
    ],
    type: 'object'
)]
final class NotionWatchState
{
}
