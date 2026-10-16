<?php

declare(strict_types=1);

namespace App\Attribute\OpenApi\Config;

use App\DTO\ImageModelRow;
use App\DTO\ModelRow;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The 200 response of GET /api/v1/config/models: the models grouped by picker, the provider list and the group allow-list summary.
 *
 * Same document as the inline attribute, see {@see RuntimeConfigResponse}.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ModelListResponse extends OA\Response
{
    public function __construct()
    {
        parent::__construct(
            response: 200,
            description: 'List of available models',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'models',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'CHAT', type: 'array', items: new OA\Items(ref: new Model(type: ModelRow::class))),
                            new OA\Property(
                                property: 'TEXT2PIC',
                                type: 'array',
                                description: 'Text-to-image rows. capabilityPrices.TEXT2PIC is the medium price at the default size.',
                                items: new OA\Items(ref: new Model(type: ImageModelRow::class))
                            ),
                            new OA\Property(
                                property: 'PIC2PIC',
                                type: 'array',
                                description: 'Image-edit rows. capabilityPrices.PIC2PIC is the high price at the default size, which is what an edit is billed.',
                                items: new OA\Items(ref: new Model(type: ImageModelRow::class))
                            ),
                        ]
                    ),
                    new OA\Property(
                        property: 'restricted',
                        type: 'array',
                        description: 'Capabilities a group allow-list limits for this member. A capability that is not listed stays unrestricted.',
                        items: new OA\Items(type: 'string', example: 'CHAT')
                    ),
                    new OA\Property(
                        property: 'groupLimits',
                        description: 'Groups whose model lists were combined for this member. Empty when nothing is restricted.',
                        required: ['names', 'combined'],
                        properties: [
                            new OA\Property(property: 'names', type: 'array', items: new OA\Items(type: 'string', example: 'Sales')),
                            new OA\Property(property: 'combined', type: 'boolean', example: false),
                        ],
                        type: 'object',
                    ),
                    new OA\Property(
                        property: 'providers',
                        type: 'array',
                        description: 'Availability of every registered AI provider on this installation (internal test provider excluded).',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'name', type: 'string', example: 'groq'),
                                new OA\Property(property: 'displayName', type: 'string', example: 'Groq'),
                                new OA\Property(property: 'available', type: 'boolean', example: true),
                                new OA\Property(property: 'requiresKey', type: 'boolean', description: 'True for cloud providers configured via a platform API key (the key wizard set); false for URL/local providers like Ollama or custom OpenAI-compatible endpoints.', example: true),
                            ]
                        )
                    ),
                ]
            ),
        );
    }
}
