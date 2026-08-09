<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Messages\MessagesGateway;
use App\AI\Messages\Tools\WebSearchTool;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Settings API for the Anthropic-compatible Messages gateway (Channels → AI Agents).
 */
#[Route('/api/v1/messages-gateway', name: 'api_messages_gateway_')]
#[OA\Tag(name: 'Messages Gateway', description: 'Anthropic-compatible Messages API gateway settings')]
final class MessagesGatewayController extends AbstractController
{
    private const SUPPORTED_PROVIDERS = ['anthropic', 'openai', 'google'];

    public function __construct(
        private readonly MessagesGatewayConfig $config,
        private readonly UserProviderKeyResolver $keyResolver,
        private readonly ProviderKeyStore $providerKeyStore,
        private readonly ConfigRepository $configRepository,
        private readonly RateLimitService $rateLimitService,
        private readonly WebSearchTool $webSearchTool,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/messages-gateway',
        summary: 'Get Messages gateway status for the signed-in user',
        security: [['Bearer' => []]],
        tags: ['Messages Gateway'],
    )]
    #[OA\Response(
        response: 200,
        description: 'Gateway status',
        content: new OA\JsonContent(
            required: ['enabled', 'upstream_url', 'keys', 'budget', 'is_admin', 'setup', 'model_aliases'],
            properties: [
                new OA\Property(property: 'enabled', type: 'boolean', example: false),
                new OA\Property(property: 'allow_operator_key', type: 'boolean', example: false),
                new OA\Property(property: 'mcp_tools_enabled', type: 'boolean', example: false),
                new OA\Property(property: 'web_search_enabled', type: 'boolean', example: false),
                new OA\Property(property: 'web_search_available', type: 'boolean', example: false, description: 'Whether a web search provider is configured on this instance.'),
                new OA\Property(property: 'context_injection_enabled', type: 'boolean', example: false),
                new OA\Property(property: 'budget_notice_enabled', type: 'boolean', example: true),
                new OA\Property(property: 'upstream_url', type: 'string', example: 'https://api.anthropic.com'),
                new OA\Property(
                    property: 'model_aliases',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'string'),
                    example: ['claude-sonnet-4-6' => 'claude-sonnet-4-5-20250929'],
                ),
                new OA\Property(
                    property: 'keys',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(
                        properties: [
                            new OA\Property(property: 'has_user_key', type: 'boolean'),
                            new OA\Property(property: 'user_key_masked', type: 'string'),
                            new OA\Property(property: 'has_operator_key', type: 'boolean'),
                            new OA\Property(property: 'effective_source', type: 'string', enum: ['user', 'operator', 'none']),
                        ],
                        type: 'object',
                    ),
                ),
                new OA\Property(
                    property: 'budget',
                    properties: [
                        new OA\Property(property: 'percent', type: 'number', example: 12.5),
                        new OA\Property(property: 'used_cost', type: 'string', example: '0.50'),
                        new OA\Property(property: 'budget', type: 'string', example: '5.00'),
                        new OA\Property(property: 'remaining', type: 'string', example: '4.50'),
                        new OA\Property(property: 'allowed', type: 'boolean', example: true),
                    ],
                    type: 'object',
                ),
                new OA\Property(property: 'is_admin', type: 'boolean', example: false),
                new OA\Property(
                    property: 'setup',
                    properties: [
                        new OA\Property(property: 'base_url_hint', type: 'string'),
                        new OA\Property(property: 'env_api_key', type: 'string', example: 'ANTHROPIC_API_KEY'),
                        new OA\Property(property: 'env_auth_token', type: 'string', example: 'ANTHROPIC_AUTH_TOKEN'),
                        new OA\Property(property: 'note', type: 'string'),
                    ],
                    type: 'object',
                ),
            ],
        ),
    )]
    public function status(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $userId = (int) $user->getId();
        $budget = $this->rateLimitService->checkCostBudget($user);
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $keys = [];
        foreach (self::SUPPORTED_PROVIDERS as $provider) {
            $resolved = $this->keyResolver->resolve(
                $provider,
                $userId,
                $this->config->allowOperatorKey($userId),
            );
            $keys[$provider] = [
                'has_user_key' => $this->keyResolver->hasUserKey($userId, $provider),
                'user_key_masked' => $this->keyResolver->maskedUserKey($userId, $provider),
                'has_operator_key' => null !== $this->providerKeyStore->getKey($provider),
                'effective_source' => $resolved['source'] ?? 'none',
            ];
        }

        // Use JsonResponse (not $this->json): the Symfony serializer normalizes
        // an empty stdClass to [], which breaks the frontend Zod record schema.
        return new JsonResponse([
            'enabled' => $this->config->isEnabled($userId),
            'allow_operator_key' => $this->config->allowOperatorKey($userId),
            'mcp_tools_enabled' => $this->config->isMcpToolsEnabled($userId),
            'web_search_enabled' => $this->config->isWebSearchEnabled($userId),
            'web_search_available' => $this->webSearchTool->isAvailable(),
            'context_injection_enabled' => $this->config->isContextInjectionEnabled($userId),
            'budget_notice_enabled' => $this->config->isBudgetNoticeEnabled($userId),
            'upstream_url' => $this->config->upstreamUrl(),
            'model_aliases' => (object) $this->config->modelAliases(),
            'keys' => $keys,
            'budget' => [
                'percent' => $budget['percent'],
                'used_cost' => $budget['used_cost'],
                'budget' => $budget['budget'],
                'remaining' => $budget['remaining'],
                'allowed' => $budget['allowed'],
            ],
            'is_admin' => $isAdmin,
            'setup' => [
                'base_url_hint' => '(your Synaplan origin, e.g. https://web.synaplan.com)',
                'env_api_key' => 'ANTHROPIC_API_KEY',
                'env_auth_token' => 'ANTHROPIC_AUTH_TOKEN',
                'note' => 'Set exactly one of ANTHROPIC_API_KEY (x-api-key) or ANTHROPIC_AUTH_TOKEN (Bearer).',
            ],
        ]);
    }

    #[Route('/keys/{provider}', name: 'put_key', methods: ['PUT', 'POST'], requirements: ['provider' => 'anthropic|openai|google'])]
    #[OA\Put(
        path: '/api/v1/messages-gateway/keys/{provider}',
        summary: 'Save a BYO provider API key for the Messages gateway',
        security: [['Bearer' => []]],
        tags: ['Messages Gateway'],
    )]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['anthropic', 'openai', 'google']))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['api_key'],
            properties: [
                new OA\Property(property: 'api_key', type: 'string', example: 'sk-ant-...'),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Key saved',
        content: new OA\JsonContent(
            required: ['success', 'provider', 'user_key_masked'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'provider', type: 'string', example: 'anthropic'),
                new OA\Property(property: 'user_key_masked', type: 'string', example: 'sk-a****'),
            ],
        ),
    )]
    #[OA\Response(
        response: 403,
        description: 'BYO keys require at least the Pro plan',
        content: new OA\JsonContent(
            required: ['error'],
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Using your own provider API key requires at least the Pro plan.'),
            ],
        ),
    )]
    public function putKey(string $provider, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if (!\in_array($user->getRateLimitLevel(), MessagesGateway::BYO_ALLOWED_LEVELS, true)) {
            return $this->json(
                ['error' => 'Using your own provider API key requires at least the Pro plan. Upgrade your Synaplan subscription to save a BYO key.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $provider = strtolower($provider);
        if (!\in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return $this->json(['error' => 'Unsupported provider'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $decoded = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        $apiKey = trim((string) (($decoded['api_key'] ?? '') ?: ''));
        if ('' === $apiKey || str_contains($apiKey, '*')) {
            return $this->json(['error' => 'api_key is required'], Response::HTTP_BAD_REQUEST);
        }

        $userId = (int) $user->getId();
        $this->keyResolver->saveUserKey($userId, $provider, $apiKey);

        $this->logger->info('MessagesGateway: BYO key saved', [
            'user_id' => $userId,
            'provider' => $provider,
        ]);

        return $this->json([
            'success' => true,
            'provider' => $provider,
            'user_key_masked' => $this->keyResolver->maskedUserKey($userId, $provider),
        ]);
    }

    #[Route('/keys/{provider}', name: 'delete_key', methods: ['DELETE'], requirements: ['provider' => 'anthropic|openai|google'])]
    #[OA\Delete(
        path: '/api/v1/messages-gateway/keys/{provider}',
        summary: 'Clear the BYO provider API key for the Messages gateway',
        security: [['Bearer' => []]],
        tags: ['Messages Gateway'],
    )]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['anthropic', 'openai', 'google']))]
    #[OA\Response(
        response: 200,
        description: 'Key cleared',
        content: new OA\JsonContent(
            required: ['success', 'provider'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'provider', type: 'string', example: 'anthropic'),
            ],
        ),
    )]
    public function deleteKey(string $provider, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $provider = strtolower($provider);
        $userId = (int) $user->getId();
        $this->keyResolver->clearUserKey($userId, $provider);

        return $this->json(['success' => true, 'provider' => $provider]);
    }

    #[Route('/upstream', name: 'put_upstream', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/messages-gateway/upstream',
        summary: 'Set the global upstream URL (admin only)',
        security: [['Bearer' => []]],
        tags: ['Messages Gateway'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['upstream_url'],
            properties: [
                new OA\Property(property: 'upstream_url', type: 'string', example: 'https://api.anthropic.com'),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Upstream URL updated',
        content: new OA\JsonContent(
            required: ['success', 'upstream_url'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'upstream_url', type: 'string', example: 'https://api.anthropic.com'),
            ],
        ),
    )]
    public function putUpstream(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        try {
            $decoded = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        $url = trim((string) ($decoded['upstream_url'] ?? ''));

        try {
            $this->config->setUpstreamUrl($url, (int) $user->getId());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'success' => true,
            'upstream_url' => $this->config->upstreamUrl(),
        ]);
    }

    #[Route('/aliases', name: 'put_aliases', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/messages-gateway/aliases',
        summary: 'Set global MODEL_ALIASES map (admin only)',
        security: [['Bearer' => []]],
        tags: ['Messages Gateway'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['model_aliases'],
            properties: [
                new OA\Property(
                    property: 'model_aliases',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'string'),
                ),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Aliases updated',
        content: new OA\JsonContent(
            required: ['success', 'model_aliases'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'model_aliases',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'string'),
                ),
            ],
        ),
    )]
    public function putAliases(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        try {
            $decoded = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        $aliases = $decoded['model_aliases'] ?? null;
        if (!\is_array($aliases)) {
            return $this->json(['error' => 'model_aliases must be an object'], Response::HTTP_BAD_REQUEST);
        }

        $clean = [];
        foreach ($aliases as $from => $to) {
            if (\is_string($from) && '' !== $from && \is_string($to) && '' !== $to) {
                $clean[$from] = $to;
            }
        }

        $this->configRepository->setValue(
            0,
            MessagesGatewayConfig::CONFIG_GROUP,
            MessagesGatewayConfig::KEY_MODEL_ALIASES,
            // Force object encoding so {} stays {} (never []) in BCONFIG.
            json_encode((object) $clean, \JSON_THROW_ON_ERROR),
        );

        return new JsonResponse(['success' => true, 'model_aliases' => (object) $clean]);
    }

    #[Route('/flags', name: 'put_flags', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/messages-gateway/flags',
        summary: 'Set global gateway feature flags (admin only)',
        security: [['Bearer' => []]],
        tags: ['Messages Gateway'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'enabled', type: 'boolean'),
                new OA\Property(property: 'allow_operator_key', type: 'boolean'),
                new OA\Property(property: 'mcp_tools_enabled', type: 'boolean'),
                new OA\Property(property: 'web_search_enabled', type: 'boolean'),
                new OA\Property(property: 'context_injection_enabled', type: 'boolean'),
                new OA\Property(property: 'budget_notice_enabled', type: 'boolean'),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Flags updated',
        content: new OA\JsonContent(
            required: ['success', 'updated'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'updated',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'boolean'),
                ),
            ],
        ),
    )]
    public function putFlags(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        try {
            $decoded = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        $allowed = [
            MessagesGatewayConfig::KEY_ENABLED,
            MessagesGatewayConfig::KEY_ALLOW_OPERATOR_KEY,
            MessagesGatewayConfig::KEY_MCP_TOOLS_ENABLED,
            MessagesGatewayConfig::KEY_WEB_SEARCH_ENABLED,
            MessagesGatewayConfig::KEY_CONTEXT_INJECTION_ENABLED,
            MessagesGatewayConfig::KEY_BUDGET_NOTICE_ENABLED,
        ];

        $updated = [];
        foreach ($allowed as $key) {
            $jsonKey = strtolower($key);
            if (!\array_key_exists($jsonKey, $decoded) && !\array_key_exists($key, $decoded)) {
                continue;
            }
            $value = $decoded[$jsonKey] ?? $decoded[$key];
            $bool = filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
            if (null === $bool) {
                continue;
            }
            $this->configRepository->setValue(
                0,
                MessagesGatewayConfig::CONFIG_GROUP,
                $key,
                $bool ? '1' : '0',
            );
            $updated[$jsonKey] = $bool;
        }

        $this->logger->warning('MessagesGateway: flags updated (audit)', [
            'acting_user_id' => $user->getId(),
            'updated' => $updated,
        ]);

        return $this->json(['success' => true, 'updated' => $updated]);
    }
}
