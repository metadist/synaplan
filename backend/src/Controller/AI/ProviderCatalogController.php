<?php

declare(strict_types=1);

namespace App\Controller\AI;

use App\AI\Catalog\ProviderCatalogPresenter;
use App\Entity\User;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The provider catalog as the user interface reads it: names, capabilities,
 * icons, flags, help keys and which credential a key row asks for. Every
 * signed-in user may read it — it carries no secret, no endpoint and no
 * validation request. The frontend's provider icons, help hints and pickers
 * take their names from here instead of a bundled list.
 */
#[Route('/api/v1/ai/providers', name: 'ai_providers_')]
#[OA\Tag(name: 'AI Providers')]
final class ProviderCatalogController extends AbstractController
{
    public function __construct(
        private readonly ProviderCatalogPresenter $presenter,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/ai/providers',
        summary: 'List every AI provider of this installation with its display name, capabilities, branding and key requirements',
        security: [['Bearer' => []]],
        tags: ['AI Providers'],
    )]
    #[OA\Response(
        response: 200,
        description: 'The provider catalog, keyed providers first in their Admin → AI keys order, then the rest by name',
        content: new OA\JsonContent(
            required: ['providers'],
            properties: [
                new OA\Property(
                    property: 'providers',
                    type: 'array',
                    items: new OA\Items(
                        required: ['name', 'displayName', 'capabilities', 'icon', 'iconComponent', 'flag', 'local', 'helpKey', 'helpUrl', 'helpIsDownload', 'helpFields', 'key'],
                        properties: [
                            new OA\Property(property: 'name', type: 'string', description: 'Lowercase provider name; equals the model service name', example: 'groq'),
                            new OA\Property(property: 'displayName', type: 'string', example: 'Groq'),
                            new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string', enum: ['chat', 'embedding', 'vision', 'image_generation', 'video_generation', 'speech_to_text', 'text_to_speech', 'file_analysis']), example: ['chat', 'vision', 'speech_to_text']),
                            new OA\Property(property: 'icon', type: 'string', description: 'Iconify id of the brand glyph', example: 'simple-icons:groq'),
                            new OA\Property(property: 'iconComponent', type: 'string', nullable: true, description: 'Name of a bundled Vue glyph that replaces the Iconify id where the app has it', example: 'GroqIcon'),
                            new OA\Property(property: 'flag', type: 'string', description: 'Iconify id of the jurisdiction badge; mdi:map-marker for a self-hosted engine', example: 'circle-flags:us'),
                            new OA\Property(property: 'local', type: 'boolean', description: 'Runs on the operator\'s machine', example: false),
                            new OA\Property(property: 'helpKey', type: 'string', nullable: true, description: 'Locale key under admin.providerHelp; null when the provider has no help hint', example: 'groq'),
                            new OA\Property(property: 'helpUrl', type: 'string', nullable: true, description: 'Where the help hint links: the key console, or a download page', example: 'https://console.groq.com/keys'),
                            new OA\Property(property: 'helpIsDownload', type: 'boolean', description: 'The help link is a download / setup flow rather than a key console', example: false),
                            new OA\Property(property: 'helpFields', type: 'array', items: new OA\Items(type: 'string'), description: 'System-config field keys that show this provider\'s help hint', example: ['GROQ_API_KEY']),
                            new OA\Property(
                                property: 'key',
                                type: 'object',
                                nullable: true,
                                description: 'How the provider is authenticated; null when it needs no credential',
                                required: ['store', 'envVar', 'secretEnvVar', 'consoleUrl', 'userCredential', 'servesChat'],
                                properties: [
                                    new OA\Property(property: 'store', type: 'string', enum: ['platform', 'endpoint_registry', 'none'], example: 'platform'),
                                    new OA\Property(property: 'envVar', type: 'string', nullable: true, example: 'GROQ_API_KEY'),
                                    new OA\Property(property: 'secretEnvVar', type: 'string', nullable: true, example: null),
                                    new OA\Property(property: 'consoleUrl', type: 'string', nullable: true, example: 'https://console.groq.com/keys'),
                                    new OA\Property(property: 'userCredential', type: 'string', enum: ['none', 'gateway', 'personal'], description: 'Whether a person may bring their own key: on the Messages gateway, or a personal pair the provider resolves', example: 'none'),
                                    new OA\Property(property: 'servesChat', type: 'boolean', description: 'False for media and speech keys', example: true),
                                ],
                            ),
                        ],
                        type: 'object',
                    ),
                ),
            ],
            type: 'object',
        ),
    )]
    #[OA\Response(response: 401, description: 'Not signed in', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string')], type: 'object'))]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['providers' => $this->presenter->list()]);
    }
}
