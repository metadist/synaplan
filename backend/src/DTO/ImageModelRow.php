<?php

declare(strict_types=1);

namespace App\DTO;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One row in the text-to-image or image-edit dropdown.
 * Chat rows do not use this schema and do not carry capabilityPrices.
 */
#[OA\Schema(
    schema: 'ImageModelRow',
    allOf: [
        new OA\Schema(ref: new Model(type: ModelRow::class)),
        new OA\Schema(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'capabilityPrices',
                    type: 'object',
                    description: 'Per-image price the dropdown badges. TEXT2PIC is medium quality at the default size. PIC2PIC is high quality at that size, which is what an edit is billed.',
                    required: ['TEXT2PIC', 'PIC2PIC'],
                    properties: [
                        new OA\Property(property: 'TEXT2PIC', type: 'number', example: 0.01317),
                        new OA\Property(property: 'PIC2PIC', type: 'number', example: 0.05268),
                    ]
                ),
            ]
        ),
    ]
)]
final class ImageModelRow
{
}
