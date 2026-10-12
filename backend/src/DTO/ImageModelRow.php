<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * One row in the text-to-image or image-edit dropdown.
 * Chat rows do not use this schema and do not carry capabilityPrices.
 */
#[OA\Schema(
    schema: 'ImageModelRow',
    required: ['id', 'name', 'priceIn', 'priceOut', 'capabilityPrices'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 348),
        new OA\Property(property: 'service', type: 'string', example: 'OpenAI'),
        new OA\Property(property: 'name', type: 'string', example: 'GPT Image 2.5 Flare'),
        new OA\Property(property: 'priceIn', type: 'number', example: 0),
        new OA\Property(property: 'priceOut', type: 'number', example: 0.01317, description: 'Flat medium 1024² price. The badge uses capabilityPrices instead.'),
        new OA\Property(property: 'priceKnown', type: 'boolean', example: true),
        new OA\Property(
            property: 'capabilityPrices',
            required: ['TEXT2PIC', 'PIC2PIC'],
            description: 'Per-image price the dropdown badges. TEXT2PIC is medium quality at the default size. PIC2PIC is high quality at that size, which is what an edit is billed.',
            properties: [
                new OA\Property(property: 'TEXT2PIC', type: 'number', example: 0.01317),
                new OA\Property(property: 'PIC2PIC', type: 'number', example: 0.05268),
            ],
            type: 'object',
        ),
    ]
)]
final class ImageModelRow
{
}
