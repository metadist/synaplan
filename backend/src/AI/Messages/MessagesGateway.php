<?php

declare(strict_types=1);

namespace App\AI\Messages;

use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Messages\Mcp\McpToolLoop;
use App\AI\Messages\MessagesContextInjector;
use App\AI\Messages\Translator\AnthropicPassthroughTranslator;
use App\Entity\User;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Orchestrates a Messages API request: feature flag, budget, model resolve,
 * credential resolve, optional body mutation, translator dispatch, metering.
 *
 * MCP tool loop (§ Phase 2) and context injection (§ Phase 3) are gated by
 * config flags (default off).
 *
 * @phpstan-type GatewaySuccess array{
 *     ok: true,
 *     stream: bool,
 *     status: int,
 *     headers: array<string, string>,
 *     body: array<string, mixed>|string|null,
 *     raw_stream: bool,
 *     usage: MessagesUsage,
 *     key_source: 'user'|'operator',
 *     resolved: array{
 *         provider: string,
 *         providerModelId: string,
 *         displayModel: string,
 *         model_id: int,
 *         requested: string,
 *         aliased_from: string|null
 *     },
 *     budget: array<string, mixed>,
 *     session_id: string|null,
 *     body_mutated: bool,
 *     request_body: array<string, mixed>,
 *     translator_context: array<string, mixed>,
 *     mcp_loop: bool,
 *     mcp_catalog: array{
 *         tools: list<array{name: string, description: string, input_schema: array<string, mixed>}>,
 *         dispatch: array<string, array{serverId: int, tool: string, annotations: array<string, mixed>}>
 *     }|null,
 *     context_hash: string|null,
 *     debug: bool
 * }
 * @phpstan-type GatewayError array{
 *     ok: false,
 *     status: int,
 *     error_type: string,
 *     message: string,
 *     headers?: array<string, string>
 * }
 */
final readonly class MessagesGateway
{
    private const BUDGET_NOTICE_CACHE_PREFIX = 'messages_gateway_budget_notice_';
    private const BUDGET_NOTICE_TTL = 7200;

    /**
     * @param iterable<MessagesTranslatorInterface> $translators
     */
    public function __construct(
        private MessagesGatewayConfig $config,
        private MessagesModelResolver $modelResolver,
        private UserProviderKeyResolver $keyResolver,
        private RateLimitService $rateLimitService,
        private AnthropicPassthroughTranslator $anthropicPassthrough,
        private McpToolLoop $mcpToolLoop,
        private MessagesContextInjector $contextInjector,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        #[AutowireIterator('app.messages.translator')]
        private iterable $translators = [],
    ) {
    }

    /**
     * @return GatewaySuccess|GatewayError
     */
    public function prepare(Request $request, User $user): array
    {
        if (!$this->config->isEnabled($user->getId())) {
            return $this->err(403, 'permission_error', 'Messages gateway is disabled on this Synaplan instance.');
        }

        $rawBody = $request->getContent();
        $decoded = json_decode($rawBody, true);
        if (!\is_array($decoded)) {
            return $this->err(400, 'invalid_request_error', 'Invalid JSON body.');
        }

        if (!isset($decoded['messages']) || !\is_array($decoded['messages']) || [] === $decoded['messages']) {
            return $this->err(400, 'invalid_request_error', 'messages is required and must be a non-empty array.');
        }

        if (!isset($decoded['max_tokens'])) {
            return $this->err(400, 'invalid_request_error', 'max_tokens is required.');
        }

        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$rateLimitCheck['allowed']) {
            return $this->err(
                429,
                'rate_limit_error',
                'Rate limit exceeded. Try again later.',
                $this->rateLimitHeaders($rateLimitCheck),
            );
        }

        $budget = $this->rateLimitService->checkCostBudget($user);
        if (!$budget['allowed']) {
            return $this->err(
                429,
                'rate_limit_error',
                sprintf(
                    'Synaplan cost budget exceeded (%.2f of %.2f used). Top up or wait for the next billing period.',
                    (float) $budget['used_cost'],
                    (float) $budget['budget'],
                ),
                $this->budgetHeaders($budget),
            );
        }

        $modelString = isset($decoded['model']) && \is_string($decoded['model']) ? $decoded['model'] : null;
        $resolved = $this->modelResolver->resolve($modelString);
        if (null === $resolved) {
            $suggestions = $this->modelResolver->listResolvableAnthropicModelIds();
            $hint = [] === $suggestions
                ? 'No active Anthropic models are configured.'
                : 'Resolvable Anthropic models: '.implode(', ', array_slice($suggestions, 0, 20));

            return $this->err(
                404,
                'not_found_error',
                sprintf(
                    'The model `%s` does not exist or is not available on this Synaplan instance. %s',
                    $modelString ?? '(none)',
                    $hint,
                ),
            );
        }

        $allowOperator = $this->config->allowOperatorKey($user->getId());
        $credential = $this->keyResolver->resolve($resolved['provider'], $user->getId(), $allowOperator);
        if (null === $credential) {
            return $this->err(
                401,
                'authentication_error',
                sprintf(
                    'No API key available for provider `%s`. Save a BYO key in Channels → AI Agents%s.',
                    $resolved['provider'],
                    $allowOperator ? '' : ' (operator-key fallback is disabled)',
                ),
            );
        }

        $translator = $this->pickTranslator($resolved['provider']);
        if (null === $translator) {
            return $this->err(
                400,
                'invalid_request_error',
                sprintf(
                    'Provider `%s` is not supported by the Messages gateway. Use Anthropic, OpenAI, or Google (Gemini), or add a MODEL_ALIASES entry.',
                    $resolved['provider'],
                ),
            );
        }

        $stream = (bool) ($decoded['stream'] ?? false);
        $bodyMutated = false;
        $requestBody = $decoded;

        // Alias rewrite: if the resolved provider model id differs from the
        // requested string, rewrite `model` so the upstream receives a real id.
        if ($resolved['providerModelId'] !== ($decoded['model'] ?? null)) {
            $requestBody['model'] = $resolved['providerModelId'];
            $bodyMutated = true;
        }

        $sessionId = $request->headers->get('x-claude-code-session-id');
        $sessionKey = $this->sessionKey($sessionId, $user, $requestBody);

        $mcpLoop = false;
        $mcpCatalog = null;
        if ($this->config->isMcpToolsEnabled($user->getId())) {
            $clientHasTools = isset($requestBody['tools']) && \is_array($requestBody['tools']) && [] !== $requestBody['tools'];
            if ($clientHasTools && !$this->config->allowMcpToolsWithClientTools($user->getId())) {
                $this->logger->debug('MessagesGateway: MCP tools skipped (client supplied tools)');
            } else {
                $mcpCatalog = $this->mcpToolLoop->pinnedCatalog($user, $sessionKey);
                if ([] !== $mcpCatalog['tools']) {
                    // Injection happens inside McpToolLoop so the catalog is
                    // applied once per upstream call; mark body mutated so the
                    // raw-body fast path is disabled.
                    $bodyMutated = true;
                    $mcpLoop = true;
                }
            }
        }

        $contextHash = null;
        $contextOverride = $request->headers->get('x-synaplan-context');
        $injectContext = $this->config->isContextInjectionEnabled($user->getId());
        if ('on' === strtolower((string) $contextOverride)) {
            $injectContext = true;
        }
        if ($injectContext) {
            $injected = $this->contextInjector->inject(
                $requestBody,
                $user,
                $sessionKey,
                $contextOverride,
            );
            $requestBody = $injected['body'];
            if ($injected['injected']) {
                $bodyMutated = true;
                $contextHash = $injected['hash'];
            }
        }

        $translatorContext = [
            'api_key' => $credential['key'],
            'upstream_url' => $this->config->upstreamUrl(),
            'anthropic_version' => $request->headers->get('anthropic-version'),
            'anthropic_beta' => $request->headers->get('anthropic-beta'),
            'x_fixture' => $request->headers->get('x-fixture'),
            'raw_body' => $bodyMutated ? null : $rawBody,
        ];

        $headers = array_merge(
            $this->rateLimitHeaders($rateLimitCheck),
            $this->budgetHeaders($budget),
        );

        return [
            'ok' => true,
            'stream' => $stream,
            'status' => 200,
            'headers' => $headers,
            'body' => null,
            'raw_stream' => $translator instanceof AnthropicPassthroughTranslator && !$mcpLoop,
            'usage' => new MessagesUsage(),
            'key_source' => $credential['source'],
            'resolved' => $resolved,
            'budget' => $budget,
            'session_id' => $sessionId,
            'body_mutated' => $bodyMutated,
            'request_body' => $requestBody,
            'translator_context' => $translatorContext,
            'mcp_loop' => $mcpLoop,
            'mcp_catalog' => $mcpCatalog,
            'context_hash' => $contextHash,
            'debug' => '1' === $request->headers->get('x-synaplan-debug'),
        ];
    }

    /**
     * Execute a prepared non-streaming request.
     *
     * @param GatewaySuccess $prepared
     *
     * @return GatewaySuccess|GatewayError
     */
    public function executeComplete(array $prepared, User $user): array
    {
        $translator = $this->pickTranslator($prepared['resolved']['provider']);
        if (null === $translator) {
            return $this->err(500, 'api_error', 'No translator available.');
        }

        try {
            if ($prepared['mcp_loop'] && null !== $prepared['mcp_catalog']) {
                $result = $this->mcpToolLoop->runComplete(
                    $prepared['request_body'],
                    $prepared['translator_context'],
                    $translator,
                    $user,
                    $prepared['mcp_catalog'],
                );
            } else {
                $result = $translator->complete($prepared['request_body'], $prepared['translator_context']);
            }
        } catch (\Throwable $e) {
            $this->logger->error('MessagesGateway: complete failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);

            return $this->err(502, 'api_error', 'Upstream request failed: '.$e->getMessage());
        }

        $status = $result['status'];
        $body = $result['body'];
        $usage = $result['usage'];

        if ($status < 400 && \is_array($body)) {
            $this->recordUsage($user, $prepared, $usage, $body);
            $body = $this->maybeAppendBudgetNoticeNonStream($prepared, $body, $usage);
        }

        $responseHeaders = $prepared['headers'];
        if ('user' === $prepared['key_source']) {
            $responseHeaders = array_merge($responseHeaders, $this->extractUpstreamRateLimitHeaders($result['headers']));
        }

        return [
            ...$prepared,
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $body,
            'usage' => $usage,
        ];
    }

    /**
     * Stream via the translator. $emit receives raw SSE strings (passthrough)
     * or synthesized event arrays. Returns final usage after the stream ends.
     *
     * @param GatewaySuccess                                                          $prepared
     * @param callable(string|array{event: string, data: array<string, mixed>}): void $emit
     */
    public function executeStream(array $prepared, User $user, callable $emit): MessagesUsage
    {
        $translator = $this->pickTranslator($prepared['resolved']['provider']);
        if (null === $translator) {
            $emit(json_encode([
                'type' => 'error',
                'error' => ['type' => 'api_error', 'message' => 'No translator available.'],
            ], \JSON_THROW_ON_ERROR));

            return new MessagesUsage();
        }

        if ($prepared['mcp_loop'] && null !== $prepared['mcp_catalog']) {
            $usage = $this->mcpToolLoop->runStream(
                $prepared['request_body'],
                $prepared['translator_context'],
                $translator,
                $user,
                $prepared['mcp_catalog'],
                $emit,
            );
        } else {
            $usage = $translator->stream($prepared['request_body'], $prepared['translator_context'], $emit);
        }

        if ($usage->outputTokens > 0 || $usage->inputTokens > 0) {
            $this->recordUsage($user, $prepared, $usage, null);
        }

        return $usage;
    }

    /**
     * @param array<string, mixed> $requestBody
     */
    private function sessionKey(?string $sessionId, User $user, array $requestBody): string
    {
        if (null !== $sessionId && '' !== $sessionId) {
            return $sessionId;
        }

        $firstUser = '';
        foreach ($requestBody['messages'] ?? [] as $msg) {
            if (!\is_array($msg) || 'user' !== ($msg['role'] ?? '')) {
                continue;
            }
            $content = $msg['content'] ?? '';
            $firstUser = \is_string($content)
                ? $content
                : (json_encode($content, \JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
            break;
        }

        return hash('sha256', (string) $user->getId().'|'.$firstUser);
    }

    /**
     * Whether a one-time budget notice should be spliced into this response.
     *
     * @param GatewaySuccess $prepared
     */
    public function shouldEmitBudgetNotice(array $prepared, MessagesUsage $usage, ?int $userId = null): bool
    {
        if (!$this->config->isBudgetNoticeEnabled($userId)) {
            return false;
        }

        $budget = $prepared['budget'];
        $budgetAmount = (float) ($budget['budget'] ?? 0);
        if ($budgetAmount <= 0) {
            return false; // unlimited
        }

        $percent = (float) ($budget['percent'] ?? 0);
        if ($percent < 90.0) {
            return false;
        }

        if ('end_turn' !== ($usage->stopReason ?? '')) {
            return false;
        }

        $sessionKey = $prepared['session_id'] ?? null;
        if (null === $sessionKey || '' === $sessionKey) {
            $sessionKey = 'anon-'.$prepared['resolved']['model_id'];
        }

        $item = $this->cache->getItem(self::BUDGET_NOTICE_CACHE_PREFIX.hash('sha256', $sessionKey));
        if ($item->isHit()) {
            return false;
        }

        $item->set(1);
        $item->expiresAfter(self::BUDGET_NOTICE_TTL);
        $this->cache->save($item);

        return true;
    }

    public function budgetNoticeText(array $budget): string
    {
        return sprintf(
            'Synaplan: %.0f%% of your monthly budget used (%.2f of %.2f).',
            (float) ($budget['percent'] ?? 0),
            (float) ($budget['used_cost'] ?? 0),
            (float) ($budget['budget'] ?? 0),
        );
    }

    private function pickTranslator(string $provider): ?MessagesTranslatorInterface
    {
        $provider = strtolower($provider);
        foreach ($this->translators as $translator) {
            if ($translator->supports($provider)) {
                return $translator;
            }
        }

        // Fallback: Anthropic passthrough is always registered even if the
        // iterator tag is missing during early boot/tests.
        if ($this->anthropicPassthrough->supports($provider)) {
            return $this->anthropicPassthrough;
        }

        return null;
    }

    /**
     * @param GatewaySuccess            $prepared
     * @param array<string, mixed>|null $responseBody
     */
    private function recordUsage(User $user, array $prepared, MessagesUsage $usage, ?array $responseBody): void
    {
        $inputText = '';
        foreach (array_reverse($prepared['request_body']['messages'] ?? []) as $msg) {
            if (!\is_array($msg) || 'user' !== ($msg['role'] ?? '')) {
                continue;
            }
            $content = $msg['content'] ?? '';
            $inputText = \is_string($content) ? $content : (json_encode($content, \JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
            break;
        }

        $responseText = '';
        if (null !== $responseBody && isset($responseBody['content']) && \is_array($responseBody['content'])) {
            foreach ($responseBody['content'] as $block) {
                if (\is_array($block) && 'text' === ($block['type'] ?? '') && isset($block['text'])) {
                    $responseText .= (string) $block['text'];
                }
            }
        }

        try {
            $this->rateLimitService->recordUsage($user, 'API_CHAT', [
                'provider' => $prepared['resolved']['provider'],
                'model' => $prepared['resolved']['providerModelId'],
                'model_id' => $prepared['resolved']['model_id'],
                'usage' => $usage->toRateLimitUsage(),
                'response_text' => $responseText,
                'input_text' => $inputText,
                'source' => 'MESSAGES_API',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('MessagesGateway: recordUsage failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);
        }
    }

    /**
     * @param GatewaySuccess       $prepared
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function maybeAppendBudgetNoticeNonStream(array $prepared, array $body, MessagesUsage $usage): array
    {
        $stop = $body['stop_reason'] ?? $usage->stopReason;
        $usageWithStop = new MessagesUsage(
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheCreationTokens: $usage->cacheCreationTokens,
            cacheReadTokens: $usage->cacheReadTokens,
            stopReason: \is_string($stop) ? $stop : null,
        );

        if (!$this->shouldEmitBudgetNotice($prepared, $usageWithStop)) {
            return $body;
        }

        $content = $body['content'] ?? [];
        if (!\is_array($content)) {
            $content = [];
        }
        $content[] = [
            'type' => 'text',
            'text' => $this->budgetNoticeText($prepared['budget']),
        ];
        $body['content'] = $content;

        return $body;
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, string>
     */
    private function extractUpstreamRateLimitHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $lower = strtolower($name);
            if (str_starts_with($lower, 'anthropic-ratelimit-') || 'request-id' === $lower || 'retry-after' === $lower) {
                $out[$name] = $values[0] ?? '';
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $rateLimitCheck
     *
     * @return array<string, string>
     */
    private function rateLimitHeaders(array $rateLimitCheck): array
    {
        return [
            'anthropic-ratelimit-requests-remaining' => (string) (int) ($rateLimitCheck['remaining'] ?? 0),
            'anthropic-ratelimit-requests-limit' => (string) (int) ($rateLimitCheck['limit'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $budget
     *
     * @return array<string, string>
     */
    private function budgetHeaders(array $budget): array
    {
        return [
            'x-synaplan-budget-percent' => (string) round((float) ($budget['percent'] ?? 0), 2),
            'x-synaplan-budget-remaining' => (string) ($budget['remaining'] ?? 0),
            'x-synaplan-budget-currency' => 'EUR',
        ];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return GatewayError
     */
    private function err(int $status, string $type, string $message, array $headers = []): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'error_type' => $type,
            'message' => $message,
            'headers' => $headers,
        ];
    }
}
