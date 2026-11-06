<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Controller;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use App\Service\PluginDataService;
use OpenApi\Attributes as OA;
use Plugin\Synafastbill\Api\Exception\FastBillException;
use Plugin\Synafastbill\Api\FastBillClient;
use Plugin\Synafastbill\Api\FastBillCredentials;
use Plugin\Synafastbill\Command\CommandRouter;
use Plugin\Synafastbill\Command\PluginConfig;
use Plugin\Synafastbill\Command\RouterFactory;
use Plugin\Synafastbill\Command\SystemClock;
use Plugin\Synafastbill\Synaplan\AiCommandExtractor;
use Plugin\Synafastbill\Synaplan\PluginDataSessionStore;
use Plugin\Synafastbill\Synaplan\SymfonyHttpTransport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin Synaplan adapter for the Synafastbill plugin. It owns HTTP concerns and
 * delegates ALL business logic to the framework-free core (RouterFactory →
 * CommandRouter). Keeps credentials out of responses and logs.
 */
#[Route('/api/v1/user/{userId}/plugins/synafastbill', name: 'api_plugin_synafastbill_')]
#[OA\Tag(name: 'Synafastbill Plugin')]
final class SynafastbillController extends AbstractController
{
    private const PLUGIN_NAME = 'synafastbill';
    private const CONFIG_TYPE = 'config';
    private const CONFIG_KEY = 'settings';

    public function __construct(
        private readonly PluginDataService $pluginData,
        private readonly ModelConfigService $modelConfigService,
        private readonly AiFacade $aiFacade,
        private readonly HttpClientInterface $http,
        private readonly ModelRepository $models,
        private readonly LockFactory $locks,
    ) {
    }

    #[Route('/setup-check', name: 'setup_check', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synafastbill/setup-check',
        summary: 'Check whether FastBill credentials are configured',
        security: [['ApiKey' => []]],
        tags: ['Synafastbill Plugin'],
    )]
    #[OA\Response(response: 200, description: 'Whether FastBill credentials are configured')]
    public function setupCheck(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccess($user, $userId)) {
            return $this->unauthorized();
        }
        $settings = $this->loadSettings($userId);

        return $this->json([
            'success' => true,
            'configured' => '' !== $settings['email'] && '' !== $settings['api_key'],
        ]);
    }

    #[Route('/config', name: 'config_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synafastbill/config',
        summary: 'Get the FastBill configuration',
        security: [['ApiKey' => []]],
        tags: ['Synafastbill Plugin'],
    )]
    #[OA\Response(response: 200, description: 'Plugin configuration (API key masked)')]
    public function getConfig(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccess($user, $userId)) {
            return $this->unauthorized();
        }
        $settings = $this->loadSettings($userId);

        return $this->json([
            'success' => true,
            'email' => $settings['email'],
            'api_key_set' => '' !== $settings['api_key'],
            'api_key' => '' !== $settings['api_key'] ? $this->maskKey($settings['api_key']) : '',
            'default_currency' => $settings['default_currency'],
            'default_vat_percent' => $settings['default_vat_percent'],
            'default_unit' => $settings['default_unit'],
            'default_language' => $settings['default_language'],
        ]);
    }

    #[Route('/config', name: 'config_put', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/{userId}/plugins/synafastbill/config',
        summary: 'Save the FastBill configuration',
        security: [['ApiKey' => []]],
        tags: ['Synafastbill Plugin'],
    )]
    #[OA\Response(response: 200, description: 'Configuration saved')]
    public function putConfig(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccess($user, $userId)) {
            return $this->unauthorized();
        }
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?: [];
        $current = $this->loadSettings($userId);

        // An empty or masked API key keeps the stored one (never overwrite with a mask).
        $submittedKey = trim((string) ($body['api_key'] ?? ''));
        $apiKey = ('' === $submittedKey || str_contains($submittedKey, '*')) ? $current['api_key'] : $submittedKey;

        $settings = [
            'email' => trim((string) ($body['email'] ?? $current['email'])),
            'api_key' => $apiKey,
            'default_currency' => (string) ($body['default_currency'] ?? $current['default_currency']),
            'default_vat_percent' => (string) ($body['default_vat_percent'] ?? $current['default_vat_percent']),
            'default_unit' => (string) ($body['default_unit'] ?? $current['default_unit']),
            'default_language' => (string) ($body['default_language'] ?? $current['default_language']),
        ];
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::CONFIG_TYPE, self::CONFIG_KEY, $settings);

        return $this->json(['success' => true]);
    }

    #[Route('/test-connection', name: 'test_connection', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synafastbill/test-connection',
        summary: 'Check the FastBill credentials with a read-only call',
        security: [['ApiKey' => []]],
        tags: ['Synafastbill Plugin'],
    )]
    #[OA\Response(response: 200, description: 'Read-only credential check')]
    public function testConnection(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccess($user, $userId)) {
            return $this->unauthorized();
        }

        // Test the credentials currently in the form (so the user can verify
        // before saving); fall back to stored values for anything omitted, and
        // keep the stored API key when the field is left empty or masked.
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() ?: '[]', true) ?: [];
        $stored = $this->loadSettings($userId);
        $email = trim((string) ($body['email'] ?? '')) ?: $stored['email'];
        $submittedKey = trim((string) ($body['api_key'] ?? ''));
        $apiKey = ('' === $submittedKey || str_contains($submittedKey, '*')) ? $stored['api_key'] : $submittedKey;

        try {
            $client = new FastBillClient($this->transport(), new FastBillCredentials($email, $apiKey));
            $customers = $client->getCustomers([], 1);

            return $this->json(['success' => true, 'count' => count($customers)]);
        } catch (FastBillException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/help', name: 'help', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synafastbill/help',
        summary: 'Show the FastBill command help',
        security: [['ApiKey' => []]],
        tags: ['Synafastbill Plugin'],
    )]
    #[OA\Response(response: 200, description: 'Static command help overview')]
    public function help(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccess($user, $userId)) {
            return $this->unauthorized();
        }
        $result = $this->buildRouter($userId)->dispatch('/fastbill help', 'help_'.$userId);

        return $this->json(['success' => true, 'message' => $result->message]);
    }

    #[Route('/chat', name: 'chat', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synafastbill/chat',
        summary: 'Handle one FastBill chat turn',
        security: [['ApiKey' => []]],
        tags: ['Synafastbill Plugin'],
    )]
    #[OA\Response(response: 200, description: 'Handle one /fastbill chat turn')]
    public function chat(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccess($user, $userId)) {
            return $this->unauthorized();
        }
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?: [];
        $message = trim((string) ($body['message'] ?? ''));
        $chatId = (string) ($body['chatId'] ?? ('user_'.$userId));
        $language = is_string($body['lang'] ?? null) ? (string) $body['lang'] : null;

        if ('' === $message) {
            return $this->json(['success' => false, 'error' => 'Empty message'], Response::HTTP_BAD_REQUEST);
        }

        $sessionKey = 'chat_'.$this->sanitize($chatId);
        $lock = $this->locks->createLock('synafastbill-'.$userId.'-'.$sessionKey, 30.0);
        $lock->acquire(true);
        try {
            $result = $this->buildRouter($userId, $language)->dispatch($message, $sessionKey);
        } finally {
            $lock->release();
        }

        return $this->json([
            'success' => true,
            'type' => $result->type->value,
            'message' => $result->message,
            'data' => $result->data,
        ]);
    }

    private function buildRouter(int $userId, ?string $language = null): CommandRouter
    {
        $settings = $this->loadSettings($userId);
        $config = new PluginConfig(
            language: null !== $language && '' !== $language ? $language : $settings['default_language'],
            currency: $settings['default_currency'],
            vatPercent: (float) $settings['default_vat_percent'],
            unit: $settings['default_unit'],
        );

        return RouterFactory::create(
            $this->buildClient($userId),
            new PluginDataSessionStore($this->pluginData, $userId),
            $config,
            new SystemClock(),
            new AiCommandExtractor($this->aiFacade, $userId, $this->resolveAiOptions($userId)),
        );
    }

    private function buildClient(int $userId): FastBillClient
    {
        $settings = $this->loadSettings($userId);

        return new FastBillClient(
            $this->transport(),
            new FastBillCredentials($settings['email'], $settings['api_key']),
        );
    }

    private function transport(): SymfonyHttpTransport
    {
        return new SymfonyHttpTransport($this->http);
    }

    /**
     * Best-effort AI options for the optional command extractor. The extractor
     * is a refinement only — if it is unavailable or errors, the deterministic
     * rule-based parser handles the prompt. So an empty result here is safe.
     *
     * @return array<string, mixed>
     */
    private function resolveAiOptions(int $userId): array
    {
        try {
            $modelId = $this->modelConfigService->getDefaultModel('chat', $userId);
        } catch (\Throwable) {
            return [];
        }

        if (null === $modelId) {
            return [];
        }
        $model = $this->models->find($modelId);
        if (null === $model) {
            return ['model_id' => $modelId];
        }
        $name = $model->getProviderId();
        if ('' === $name) {
            $name = $model->getName();
        }

        return [
            'model_id' => $modelId,
            'model' => $name,
            'provider' => $model->getService(),
        ];
    }

    /**
     * @return array{email: string, api_key: string, default_currency: string, default_vat_percent: string, default_unit: string, default_language: string}
     */
    private function loadSettings(int $userId): array
    {
        $stored = $this->pluginData->get($userId, self::PLUGIN_NAME, self::CONFIG_TYPE, self::CONFIG_KEY);
        $stored = is_array($stored) ? $stored : [];

        return [
            'email' => (string) ($stored['email'] ?? ''),
            'api_key' => (string) ($stored['api_key'] ?? ''),
            'default_currency' => (string) ($stored['default_currency'] ?? 'EUR'),
            'default_vat_percent' => (string) ($stored['default_vat_percent'] ?? '19'),
            'default_unit' => (string) ($stored['default_unit'] ?? 'hours'),
            'default_language' => (string) ($stored['default_language'] ?? 'en'),
        ];
    }

    private function maskKey(string $key): string
    {
        $length = mb_strlen($key);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', $length - 4).mb_substr($key, -4);
    }

    private function sanitize(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9_]/i', '_', $value);
    }

    private function canAccess(?User $user, int $userId): bool
    {
        return null !== $user && (int) $user->getId() === $userId;
    }

    private function unauthorized(): JsonResponse
    {
        return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }
}
