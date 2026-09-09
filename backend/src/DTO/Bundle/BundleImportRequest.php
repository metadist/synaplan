<?php

declare(strict_types=1);

namespace App\DTO\Bundle;

use App\Bundle\ImportOptions;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Request body of `POST /api/v1/bundle/preview` and `/import`: either the
 * bundle document itself, or this wrapper carrying the document plus options.
 */
#[OA\Schema(
    schema: 'BundleImportRequest',
    description: 'Either a BundleDocument itself, or a wrapper carrying the document plus import options.',
    properties: [
        new OA\Property(property: 'bundle', ref: new Model(type: BundleDocument::class)),
        new OA\Property(
            property: 'options',
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'conflict',
                    type: 'string',
                    enum: [ImportOptions::CONFLICT_SKIP, ImportOptions::CONFLICT_OVERWRITE],
                    default: ImportOptions::CONFLICT_SKIP,
                    description: 'What to do when an item with the same key already exists for the importer',
                ),
            ]
        ),
    ]
)]
final class BundleImportRequest
{
}
