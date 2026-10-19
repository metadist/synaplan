<?php

declare(strict_types=1);

namespace App\Attribute\OpenApi\Config;

use OpenApi\Attributes as OA;

/**
 * The 200 response of GET /api/v1/config/features: the admin status page: features, providers, modules and the file-work sidecar.
 *
 * Same document as the inline attribute, see {@see RuntimeConfigResponse}.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class FeatureStatusResponse extends OA\Response
{
    public function __construct()
    {
        parent::__construct(
            response: 200,
            description: 'Feature status map with summary',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'features',
                        type: 'object',
                        description: 'Map of feature ID to status object',
                        additionalProperties: new OA\AdditionalProperties(
                            properties: [
                                new OA\Property(property: 'id', type: 'string', example: 'web-search'),
                                new OA\Property(property: 'category', type: 'string', example: 'AI Features'),
                                new OA\Property(property: 'name', type: 'string', example: 'Web Search'),
                                new OA\Property(property: 'enabled', type: 'boolean', example: true),
                                new OA\Property(property: 'status', type: 'string', enum: ['active', 'healthy', 'unhealthy', 'disabled'], example: 'active'),
                                new OA\Property(property: 'message', type: 'string', example: 'Web search is active and ready to use'),
                                new OA\Property(property: 'setup_required', type: 'boolean', example: false),
                            ]
                        )
                    ),
                    new OA\Property(
                        property: 'summary',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'total', type: 'integer', example: 10),
                            new OA\Property(property: 'healthy', type: 'integer', example: 8),
                            new OA\Property(property: 'unhealthy', type: 'integer', example: 2),
                            new OA\Property(property: 'all_ready', type: 'boolean', example: false),
                        ]
                    ),
                    new OA\Property(
                        property: 'modules',
                        type: 'array',
                        description: 'Optional feature modules (sidecars, providers, commerce, channels) as declared by the module registry. Additive to `features`; absent modules are never probed.',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'string', example: 'office_convert'),
                                new OA\Property(property: 'label_key', type: 'string', example: 'modules.office_convert.label'),
                                new OA\Property(property: 'state', type: 'string', enum: ['absent', 'available', 'needs_setup'], example: 'available'),
                                new OA\Property(property: 'configured', type: 'boolean', example: true),
                                new OA\Property(property: 'healthy', type: 'boolean', example: true),
                                new OA\Property(property: 'message', type: 'string', example: 'Office converter is running'),
                                new OA\Property(property: 'details', type: 'object', description: 'Module-specific, never contains secrets', additionalProperties: true),
                                new OA\Property(
                                    property: 'configured_by',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'env', type: 'array', items: new OA\Items(type: 'string'), example: ['OFFICE_CONVERT_URL']),
                                        new OA\Property(property: 'bconfig', type: 'array', items: new OA\Items(type: 'string')),
                                        new OA\Property(property: 'providers', type: 'array', items: new OA\Items(type: 'string')),
                                        new OA\Property(property: 'plugs', type: 'array', items: new OA\Items(type: 'string')),
                                    ],
                                ),
                                new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['pdf_export']),
                                new OA\Property(property: 'docs_anchor', type: 'string', example: 'modules/office-convert'),
                                new OA\Property(property: 'mobile_class', type: 'string', enum: ['backend-only', 'ota-candidate'], example: 'ota-candidate'),
                            ],
                        ),
                    ),
                    new OA\Property(
                        property: 'compute',
                        type: 'object',
                        description: 'File-work compute sidecar status (CS27): tier, live capacity, pinned images, last-24h run counts',
                        properties: [
                            new OA\Property(property: 'enabled', type: 'boolean', example: true),
                            new OA\Property(property: 'reachable', type: 'boolean', example: true),
                            new OA\Property(property: 'protocol', type: 'integer', example: 1),
                            new OA\Property(property: 'tier', type: 'string', enum: ['docker', 'gvisor', 'microvm', ''], example: 'docker'),
                            new OA\Property(property: 'tierMeetsRequirement', type: 'boolean', example: true),
                            new OA\Property(
                                property: 'capacity',
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'maxConcurrent', type: 'integer', example: 2),
                                    new OA\Property(property: 'running', type: 'integer', example: 1),
                                    new OA\Property(property: 'queued', type: 'integer', example: 0),
                                ]
                            ),
                            new OA\Property(
                                property: 'images',
                                type: 'array',
                                items: new OA\Items(
                                    properties: [
                                        new OA\Property(property: 'key', type: 'string', example: 'python'),
                                        new OA\Property(property: 'digest', type: 'string', example: 'a1b2c3d4e5f6'),
                                    ]
                                )
                            ),
                            new OA\Property(property: 'runsLast24h', type: 'integer', example: 12),
                            new OA\Property(property: 'failedLast24h', type: 'integer', example: 1),
                        ]
                    ),
                    self::servicesProperty(),
                ]
            ),
        );
    }

    private static function servicesProperty(): OA\Property
    {
        return new OA\Property(
            property: 'services',
            type: 'array',
            description: 'Readiness of the stack, one row per service, cached 5 s. starting = has not answered yet (becomes failed after 10 minutes); downloading = fetching its model; off = not part of this install; unavailable = answered before, not reachable now; failed = did not come up or reported an error.',
            items: new OA\Items(
                required: ['id', 'name', 'state', 'unlocks', 'effect', 'recovery', 'since', 'percent', 'model', 'minutes_left', 'profile', 'docs_anchor'],
                properties: [
                    new OA\Property(property: 'id', type: 'string', enum: ['ollama', 'qdrant', 'whisper', 'tts', 'tika', 'docling', 'searxng', 'collabora', 'centrifugo'], example: 'ollama'),
                    new OA\Property(property: 'name', type: 'string', example: 'Ollama (local AI)'),
                    new OA\Property(property: 'state', type: 'string', enum: ['starting', 'downloading', 'ready', 'off', 'unavailable', 'failed'], example: 'downloading'),
                    new OA\Property(property: 'unlocks', type: 'string', description: 'What the service unlocks, one sentence', example: 'Private chat and embeddings on this machine.'),
                    new OA\Property(property: 'effect', type: 'string', nullable: true, description: 'What is waiting or stopped while the service is not ready; null when ready', example: 'Local AI models are not available.'),
                    new OA\Property(property: 'recovery', type: 'string', nullable: true, description: 'One sentence the admin can act on, only on installs where it applies (never on Kubernetes)', example: 'Run `docker compose logs qdrant` to see why; `docker compose restart qdrant` brings it back.'),
                    new OA\Property(property: 'since', type: 'string', format: 'date-time', nullable: true, description: 'When the current state began', example: '2026-10-19T12:00:00+00:00'),
                    new OA\Property(property: 'percent', type: 'integer', nullable: true, description: 'Download progress, only while downloading', example: 42),
                    new OA\Property(property: 'model', type: 'string', nullable: true, description: 'Model being downloaded, only while downloading', example: 'gpt-oss:20b'),
                    new OA\Property(property: 'minutes_left', type: 'integer', nullable: true, description: 'Estimated minutes until the download finishes', example: 6),
                    new OA\Property(property: 'profile', type: 'string', nullable: true, description: 'Compose profile that turns the service on, only while off', example: 'local-ai'),
                    new OA\Property(property: 'docs_anchor', type: 'string', nullable: true, example: 'modules/local-ai'),
                ],
            ),
        );
    }
}
