<?php

declare(strict_types=1);

namespace App\Attribute\OpenApi\Config;

use OpenApi\Attributes as OA;

/**
 * The 200 response of GET /api/v1/config/capabilities: the formats, languages and summary options consumer integrations discover instead of hardcoding.
 *
 * Same document as the inline attribute, see {@see RuntimeConfigResponse}.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CapabilitiesResponse extends OA\Response
{
    public function __construct()
    {
        parent::__construct(
            response: 200,
            description: 'Capability descriptor',
            content: new OA\JsonContent(
                required: ['file_formats', 'languages', 'summary', 'max_file_size_bytes'],
                properties: [
                    new OA\Property(
                        property: 'file_formats',
                        type: 'object',
                        description: 'Allowed upload extensions (lowercase, no leading dot) grouped by category. '
                            .'A category is omitted when it has no allowed extensions, and an "other" bucket holds any '
                            .'allowed extension without a dedicated category, so clients should tolerate extra keys.',
                        properties: [
                            new OA\Property(property: 'text', type: 'array', items: new OA\Items(type: 'string', example: 'txt')),
                            new OA\Property(property: 'documents', type: 'array', items: new OA\Items(type: 'string', example: 'pdf')),
                            new OA\Property(property: 'spreadsheets', type: 'array', items: new OA\Items(type: 'string', example: 'xlsx')),
                            new OA\Property(property: 'presentations', type: 'array', items: new OA\Items(type: 'string', example: 'pptx')),
                            new OA\Property(property: 'images', type: 'array', items: new OA\Items(type: 'string', example: 'png')),
                            new OA\Property(property: 'audio', type: 'array', items: new OA\Items(type: 'string', example: 'mp3')),
                            new OA\Property(property: 'video', type: 'array', items: new OA\Items(type: 'string', example: 'mp4')),
                            new OA\Property(property: 'calendar', type: 'array', items: new OA\Items(type: 'string', example: 'ics')),
                        ]
                    ),
                    new OA\Property(
                        property: 'languages',
                        type: 'array',
                        description: 'Language codes available for translation and summary output.',
                        items: new OA\Items(type: 'string', example: 'en')
                    ),
                    new OA\Property(
                        property: 'summary',
                        type: 'object',
                        required: ['types', 'lengths', 'focus_areas'],
                        properties: [
                            new OA\Property(
                                property: 'types',
                                type: 'array',
                                items: new OA\Items(type: 'string', example: 'abstractive')
                            ),
                            new OA\Property(
                                property: 'lengths',
                                type: 'array',
                                items: new OA\Items(type: 'string', example: 'medium')
                            ),
                            new OA\Property(
                                property: 'focus_areas',
                                type: 'array',
                                items: new OA\Items(type: 'string', example: 'main-ideas')
                            ),
                        ]
                    ),
                    new OA\Property(
                        property: 'max_file_size_bytes',
                        type: 'integer',
                        description: 'Maximum size of a single upload, in bytes.',
                        example: 134217728
                    ),
                ]
            ),
        );
    }
}
