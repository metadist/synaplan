<?php

declare(strict_types=1);

namespace App\Attribute\OpenApi\Config;

use OpenApi\Attributes as OA;

/**
 * The 200 response of GET /api/v1/config/models/defaults: the model that answers per capability, whether it is locked, and who set it.
 *
 * Same document as the inline attribute, see {@see RuntimeConfigResponse}.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class DefaultModelsResponse extends OA\Response
{
    public function __construct()
    {
        parent::__construct(
            response: 200,
            description: 'Default model IDs per capability (null if not configured)',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'defaults',
                        type: 'object',
                        description: 'Map of capability name to model ID (null if no default is set)',
                        properties: [
                            new OA\Property(property: 'SORT', type: 'integer', nullable: true, example: 12),
                            new OA\Property(property: 'CHAT', type: 'integer', nullable: true, example: 53),
                            new OA\Property(property: 'MEM', type: 'integer', nullable: true, example: 7),
                            new OA\Property(property: 'VECTORIZE', type: 'integer', nullable: true, example: 3),
                            new OA\Property(property: 'PIC2TEXT', type: 'integer', nullable: true, example: null),
                            new OA\Property(property: 'TEXT2PIC', type: 'integer', nullable: true, example: null),
                            new OA\Property(property: 'PIC2PIC', type: 'integer', nullable: true, example: null),
                            new OA\Property(property: 'TEXT2VID', type: 'integer', nullable: true, example: null),
                            new OA\Property(property: 'IMG2VID', type: 'integer', nullable: true, example: null),
                            new OA\Property(property: 'SOUND2TEXT', type: 'integer', nullable: true, example: null),
                            new OA\Property(property: 'TEXT2SOUND', type: 'integer', nullable: true, example: null),
                            new OA\Property(property: 'ANALYZE', type: 'integer', nullable: true, example: 53),
                        ]
                    ),
                    new OA\Property(
                        property: 'locked',
                        type: 'object',
                        description: 'Per-capability lock from the administrator (users cannot change these)',
                        additionalProperties: new OA\AdditionalProperties(type: 'boolean')
                    ),
                    new OA\Property(
                        property: 'sources',
                        type: 'object',
                        description: 'Where each default comes from: admin, group, or user',
                        additionalProperties: new OA\AdditionalProperties(type: 'string', enum: ['admin', 'group', 'user'])
                    ),
                ]
            ),
        );
    }
}
