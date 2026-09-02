<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Credential\ChatReadinessService;
use App\AI\Credential\ProviderDefaultsService;
use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Credential\ProviderKeyValidator;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\SelfAware\CapabilityInventory;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Admin management of cloud AI provider API keys (first-run wizard backend).
 *
 * Keys are stored encrypted at rest in BCONFIG via ProviderKeyStore and are
 * NEVER returned by any endpoint — responses carry a masked hint only.
 * Providers resolve keys per call, so changes apply without a restart.
 */
#[Route('/api/v1/admin/provider-keys')]
#[OA\Tag(name: 'Admin Provider Keys')]
final class AdminProviderKeysController extends AbstractController
{
    public function __construct(
        private readonly ProviderKeyStore $keyStore,
        private readonly ProviderKeyValidator $validator,
        private readonly ProviderDefaultsService $defaults,
        private readonly ConfigRepository $configRepository,
        private readonly ChatReadinessService $chatReadiness,
        private readonly ?CapabilityInventory $capabilityInventory = null,
    ) {
    }

    #[Route('', name: 'admin_provider_keys_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/provider-keys',
        summary: 'List cloud AI providers and their key status',
        security: [['Bearer' => []]],
        tags: ['Admin Provider Keys']
    )]
    #[OA\Response(
        response: 200,
        description: 'Provider key statuses (keys are never included, only masked hints)',
        content: new OA\JsonContent(
            required: ['success', 'defaultChatProvider', 'providers'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'defaultChatProvider', type: 'string', example: 'groq', description: 'Provider name currently set as global default chat provider'),
                new OA\Property(
                    property: 'providers',
                    type: 'array',
                    items: new OA\Items(
                        required: ['name', 'displayName', 'configured', 'source', 'origin', 'maskedKey', 'consoleUrl', 'envVar', 'freeTier', 'recommended'],
                        properties: [
                            new OA\Property(property: 'name', type: 'string', example: 'groq'),
                            new OA\Property(property: 'displayName', type: 'string', example: 'Groq'),
                            new OA\Property(property: 'configured', type: 'boolean', example: true),
                            new OA\Property(property: 'source', type: 'string', enum: ['db', 'env', 'none'], example: 'db'),
                            new OA\Property(property: 'origin', type: 'string', nullable: true, enum: ['env', 'ui'], description: 'How a DB-stored key entered the store', example: 'ui'),
                            new OA\Property(property: 'maskedKey', type: 'string', example: 'gsk_••••••••••••abcd'),
                            new OA\Property(property: 'consoleUrl', type: 'string', example: 'https://console.groq.com/keys'),
                            new OA\Property(property: 'envVar', type: 'string', example: 'GROQ_API_KEY'),
                            new OA\Property(property: 'freeTier', type: 'boolean', example: true),
                            new OA\Property(property: 'recommended', type: 'boolean', example: true),
                        ],
                        type: 'object'
                    )
                ),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string')], type: 'object'))]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        return $this->json([
            'success' => true,
            'defaultChatProvider' => (string) ($this->configRepository->getValue(0, 'ai', 'default_chat_provider') ?? ''),
            'providers' => $this->buildProviderList(),
        ]);
    }

    #[Route('/{provider}', name: 'admin_provider_keys_save', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/admin/provider-keys/{provider}',
        summary: 'Validate and store an API key for a cloud AI provider',
        description: 'Validates the key live against the provider API (unless validate=false), then stores it AES-256 encrypted in the database. Applies without restart. Optionally also applies the recommended default models for the provider.',
        security: [['Bearer' => []]],
        tags: ['Admin Provider Keys']
    )]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['anthropic', 'openai', 'groq', 'google', 'mistral', 'trustedtokens', 'huggingface', 'xai']))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['key'],
            properties: [
                new OA\Property(property: 'key', type: 'string', example: 'gsk_...'),
                new OA\Property(property: 'validate', type: 'boolean', default: true, description: 'Live-check the key against the provider API before saving'),
                new OA\Property(property: 'applyDefaults', type: 'boolean', default: false, description: 'Also set the recommended global default models for this provider'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Key stored (and defaults applied when requested)',
        content: new OA\JsonContent(
            required: ['success', 'provider', 'maskedKey', 'defaultsApplied'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'provider', type: 'string', example: 'groq'),
                new OA\Property(property: 'maskedKey', type: 'string', example: 'gsk_••••••••••••abcd'),
                new OA\Property(property: 'defaultsApplied', type: 'boolean', example: true),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input or the provider rejected the key', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string'), new OA\Property(property: 'status', type: 'integer', nullable: true, description: 'HTTP status returned by the provider when a live key check failed')], type: 'object'))]
    #[OA\Response(response: 403, description: 'Admin access required', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string')], type: 'object'))]
    #[OA\Response(response: 404, description: 'Unknown provider', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string')], type: 'object'))]
    public function save(string $provider, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }
        if ($resp = $this->requireKnownProvider($provider)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_string($data['key'] ?? null) || '' === trim($data['key'])) {
            return $this->json(['error' => 'Field "key" is required and must be a non-empty string.'], Response::HTTP_BAD_REQUEST);
        }

        $key = trim($data['key']);
        $validate = (bool) ($data['validate'] ?? true);
        $applyDefaults = (bool) ($data['applyDefaults'] ?? false);

        if ($validate) {
            $result = $this->validator->validate($provider, $key);
            if (!$result['ok']) {
                return $this->json([
                    'error' => $result['error'] ?? 'The provider rejected this API key.',
                    'status' => $result['status'] ?? null,
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $this->keyStore->saveKey($provider, $key, ProviderKeyStore::ORIGIN_UI);
        } catch (\InvalidArgumentException $e) {
            // Placeholder text or the masked display value — a client mistake,
            // not a server fault. The message never contains a real key.
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        // The setup banner reads a short-lived availability snapshot — drop it so
        // the new key takes effect on the next page load instead of up to 30 s later.
        $this->chatReadiness->invalidate();
        $this->capabilityInventory?->forget();

        $defaultsApplied = false;
        if ($applyDefaults && ProviderDefaultsService::supports($provider)) {
            $this->defaults->applyGlobalDefaults($provider);
            $defaultsApplied = true;
        } elseif (!$applyDefaults && ProviderDefaultsService::supports($provider)) {
            // Key saved without the checkbox — still auto-flip when the current
            // default chat provider is a cloud provider with no usable key
            // (fresh Anthropic seed). A keyless default (Ollama, test provider)
            // is a deliberate choice and is never overridden.
            $current = strtolower((string) ($this->configRepository->getValue(0, 'ai', 'default_chat_provider') ?? ''));
            $currentReady = '' !== $current
                && (!ProviderKeyCatalog::has($current) || null !== $this->keyStore->getKey($current));
            if (!$currentReady) {
                $this->defaults->applyGlobalDefaults($provider);
                $defaultsApplied = true;
            }
        }

        return $this->json([
            'success' => true,
            'provider' => $provider,
            'maskedKey' => ProviderKeyStore::mask($key),
            'defaultsApplied' => $defaultsApplied,
        ]);
    }

    #[Route('/{provider}', name: 'admin_provider_keys_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/admin/provider-keys/{provider}',
        summary: 'Delete the stored API key for a provider',
        description: 'Removes the DB-stored key. When the matching environment variable is still set, the provider stays configured: that value is imported again on the next use. The response reports this via envFallbackActive.',
        security: [['Bearer' => []]],
        tags: ['Admin Provider Keys']
    )]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(
        response: 200,
        description: 'Key deleted',
        content: new OA\JsonContent(
            required: ['success', 'envFallbackActive'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'envFallbackActive', type: 'boolean', example: false, description: 'True when the environment still holds a key for this provider, so it remains configured'),
                new OA\Property(property: 'envVar', type: 'string', example: 'GROQ_API_KEY', description: 'Name of that environment variable'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string')], type: 'object'))]
    #[OA\Response(response: 404, description: 'Unknown provider or no stored key', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string')], type: 'object'))]
    public function delete(string $provider, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }
        if ($resp = $this->requireKnownProvider($provider)) {
            return $resp;
        }

        if (!$this->keyStore->deleteKey($provider)) {
            return $this->json(['error' => 'No stored key for this provider.'], Response::HTTP_NOT_FOUND);
        }
        $this->chatReadiness->invalidate();
        $this->capabilityInventory?->forget();

        return $this->json([
            'success' => true,
            'envFallbackActive' => $this->keyStore->hasEnvKey($provider),
            'envVar' => ProviderKeyCatalog::get($provider)['envVar'],
        ]);
    }

    #[Route('/{provider}/test', name: 'admin_provider_keys_test', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/provider-keys/{provider}/test',
        summary: 'Live-test the currently configured key of a provider',
        description: 'Resolves the current key (DB or environment) and performs one authenticated request against the provider API.',
        security: [['Bearer' => []]],
        tags: ['Admin Provider Keys']
    )]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(
        response: 200,
        description: 'Test result',
        content: new OA\JsonContent(
            required: ['ok'],
            properties: [
                new OA\Property(property: 'ok', type: 'boolean', example: true),
                new OA\Property(property: 'status', type: 'integer', nullable: true, example: 200),
                new OA\Property(property: 'error', type: 'string', nullable: true, example: 'The provider rejected this API key.'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'No key configured for this provider', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string'), new OA\Property(property: 'status', type: 'integer', nullable: true, description: 'HTTP status returned by the provider when a live key check failed')], type: 'object'))]
    #[OA\Response(response: 403, description: 'Admin access required', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string')], type: 'object'))]
    #[OA\Response(response: 404, description: 'Unknown provider', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string')], type: 'object'))]
    public function test(string $provider, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }
        if ($resp = $this->requireKnownProvider($provider)) {
            return $resp;
        }

        $key = $this->keyStore->getKey($provider);
        if (null === $key) {
            return $this->json(['error' => 'No key configured for this provider.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->validator->validate($provider, $key));
    }

    #[Route('/{provider}/apply-defaults', name: 'admin_provider_keys_apply_defaults', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/provider-keys/{provider}/apply-defaults',
        summary: 'Apply the recommended global default models for a provider',
        description: 'Sets the global DEFAULTMODEL bindings (chat, sorting, planning, vision, ... where the provider covers them) via stable catalog keys and switches the default chat provider. Never touches per-user overrides.',
        security: [['Bearer' => []]],
        tags: ['Admin Provider Keys']
    )]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(
        response: 200,
        description: 'Defaults applied',
        content: new OA\JsonContent(
            required: ['success', 'provider', 'capabilities'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'provider', type: 'string', example: 'groq'),
                new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['CHAT', 'SORT', 'PLAN']),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string')], type: 'object'))]
    #[OA\Response(response: 404, description: 'Unknown provider', content: new OA\JsonContent(required: ['error'], properties: [new OA\Property(property: 'error', type: 'string')], type: 'object'))]
    public function applyDefaults(string $provider, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }
        if ($resp = $this->requireKnownProvider($provider)) {
            return $resp;
        }

        $applied = $this->defaults->applyGlobalDefaults($provider);
        $this->chatReadiness->invalidate();
        $this->capabilityInventory?->forget();

        return $this->json([
            'success' => true,
            'provider' => strtolower($provider),
            'capabilities' => array_keys($applied),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildProviderList(): array
    {
        $providers = [];
        foreach (ProviderKeyCatalog::providerNames() as $name) {
            $meta = ProviderKeyCatalog::get($name);
            $status = $this->keyStore->getStatus($name);
            $providers[] = [
                'name' => $name,
                'displayName' => $meta['displayName'],
                'configured' => $status['configured'],
                'source' => $status['source'],
                'origin' => $status['origin'],
                'maskedKey' => $status['maskedKey'],
                'consoleUrl' => $meta['consoleUrl'],
                'envVar' => $meta['envVar'],
                'freeTier' => $meta['freeTier'],
                'recommended' => $meta['recommended'],
            ];
        }

        return $providers;
    }

    private function requireAdmin(?User $user): ?JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function requireKnownProvider(string $provider): ?JsonResponse
    {
        if (!ProviderKeyCatalog::has($provider)) {
            return $this->json(['error' => sprintf('Unknown provider "%s".', $provider)], Response::HTTP_NOT_FOUND);
        }

        return null;
    }
}
