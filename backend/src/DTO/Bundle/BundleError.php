<?php

declare(strict_types=1);

namespace App\DTO\Bundle;

use OpenApi\Attributes as OA;

/**
 * Error body shared by every `/api/v1/bundle/*` route.
 */
#[OA\Schema(
    schema: 'BundleError',
    required: ['error'],
    properties: [
        new OA\Property(property: 'error', type: 'string', example: 'Send a synaplan-bundle.v1 document.'),
        new OA\Property(property: 'path', type: 'string', nullable: true, description: 'JSON path of the offending value, when known', example: '$.sections[0].kind'),
    ]
)]
final class BundleError
{
}
