<?php

declare(strict_types=1);

namespace App\Attribute\OpenApi\Config;

use OpenApi\Attributes as OA;

/**
 * The 200 response of GET /api/v1/config/models/{modelId}/check: whether one model can answer now, with a setup hint when it cannot.
 *
 * Same document as the inline attribute, see {@see RuntimeConfigResponse}.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ModelCheckResponse extends OA\Response
{
    public function __construct()
    {
        parent::__construct(
            response: 200,
            description: 'Model availability status',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'available', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'provider_type',
                        type: 'string',
                        enum: ['local', 'external', 'unknown'],
                        description: '`local` for Ollama, `external` for cloud API providers',
                        example: 'external'
                    ),
                    new OA\Property(property: 'model_name', type: 'string', example: 'llama3.2:latest'),
                    new OA\Property(property: 'service', type: 'string', example: 'ollama'),
                    new OA\Property(
                        property: 'message',
                        type: 'string',
                        nullable: true,
                        description: 'Human-readable reason when `available` is false',
                        example: 'Ollama server is not running'
                    ),
                    new OA\Property(
                        property: 'install_command',
                        type: 'string',
                        nullable: true,
                        description: 'Command to pull/install the model (Ollama only)',
                        example: 'docker compose exec ollama ollama pull llama3.2:latest'
                    ),
                    new OA\Property(
                        property: 'env_var',
                        type: 'string',
                        nullable: true,
                        description: 'Provider credential identifier (env var name) when the key is missing from both the DB store and the environment',
                        example: 'OPENAI_API_KEY'
                    ),
                    new OA\Property(
                        property: 'setup_instructions',
                        type: 'string',
                        nullable: true,
                        description: 'Short setup hint when `env_var` is present',
                        example: 'Configure OPENAI_API_KEY under Admin → AI Providers, or set it in the environment'
                    ),
                ]
            ),
        );
    }
}
