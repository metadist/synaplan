<?php

declare(strict_types=1);

namespace App\DTO;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotionWatchResponse',
    required: ['success', 'watch'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'watch', ref: new Model(type: NotionWatchState::class)),
    ],
    type: 'object'
)]
final class NotionWatchResponse
{
}
