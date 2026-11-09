<?php

declare(strict_types=1);

namespace App\DTO\Bundle;

use App\Bundle\BundleEnvelopeValidator;
use OpenApi\Attributes as OA;

/**
 * OpenAPI shape of a `synaplan-bundle.v1` envelope.
 *
 * The server-side contract lives in {@see BundleEnvelopeValidator}; this
 * class only publishes the shape so the frontend Zod schemas can be
 * generated from it. Section item payloads are section-defined and stay
 * opaque here.
 */
#[OA\Schema(
    schema: 'BundleDocument',
    description: 'A synaplan-bundle.v1 envelope. Section items are section-defined objects.',
    required: ['schema', 'createdAt', 'sourceInstance', 'sourceVersion', 'scope', 'sections'],
    properties: [
        new OA\Property(property: 'schema', type: 'string', enum: [BundleEnvelopeValidator::SCHEMA]),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', example: '2026-09-09T12:00:00+00:00'),
        new OA\Property(property: 'sourceInstance', type: 'string', description: 'sha256: fingerprint of the exporting instance', example: 'sha256:9f2c…'),
        new OA\Property(property: 'sourceVersion', type: 'string', example: '4.7.2'),
        new OA\Property(property: 'scope', type: 'string', enum: ['user', 'instance']),
        new OA\Property(
            property: 'template',
            description: 'Present when this bundle is an assistant template. Omitted on ordinary exports.',
            required: ['id', 'version', 'titleKey', 'summaryKey', 'category', 'requires', 'tryIt'],
            properties: [
                new OA\Property(property: 'id', type: 'string', example: 'offer-writer'),
                new OA\Property(property: 'version', type: 'string', example: '2026.11.1'),
                new OA\Property(property: 'titleKey', type: 'string', example: 'templates.offerWriter.title'),
                new OA\Property(property: 'summaryKey', type: 'string', example: 'templates.offerWriter.summary'),
                new OA\Property(property: 'category', type: 'string', example: 'sales'),
                new OA\Property(property: 'requires', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
                new OA\Property(property: 'tryIt', type: 'array', items: new OA\Items(type: 'string')),
            ],
            type: 'object',
            nullable: true,
        ),
        new OA\Property(
            property: 'sections',
            type: 'array',
            maxItems: BundleEnvelopeValidator::MAX_SECTIONS,
            items: new OA\Items(
                required: ['kind', 'version', 'items'],
                properties: [
                    new OA\Property(property: 'kind', type: 'string', example: 'agents'),
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        maxItems: BundleEnvelopeValidator::MAX_ITEMS,
                        items: new OA\Items(type: 'object', additionalProperties: true),
                    ),
                ]
            )
        ),
    ]
)]
final class BundleDocument
{
}
