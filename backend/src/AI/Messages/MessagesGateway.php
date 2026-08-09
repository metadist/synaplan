<?php

declare(strict_types=1);

namespace App\AI\Messages;

use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\GatewayToolLoop;
use App\AI\Messages\Translator\AnthropicPassthroughTranslator;
use App\Entity\Model;
use App\Entity\User;
use App\Message\SummarizeApiSessionCommand;
use App\Repository\ModelRepository;
use App\Service\MessagesGateway\ApiSessionSummaryService;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use App\Service\Vision\VisionModelResolver;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Orchestrates a Messages API request: feature flag, budget, model resolve,
 * credential resolve, optional body mutation, translator dispatch, metering.
 *
 * The server-side tool loop (MCP tools plus Synaplan's built-in web search) and
 * context injection are gated by config flags (default off).
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
 *     session_key: string,
 *     body_mutated: bool,
 *     request_body: array<string, mixed>,
 *     translator_context: array<string, mixed>,
 *     tool_loop: bool,
 *     tool_catalog: array{
 *         tools: list<array{name: string, description: string, input_schema: array<string, mixed>}>,
 *         dispatch: array<string, array{kind: string, serverId: int, tool: string, annotations: array<string, mixed>}>,
 *         web_search: string
 *     }|null,
 *     replaced_server_tools: list<string>,
 *     web_search: string,
 *     vision: string,
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
     * Plans allowed to use a BYO provider key through the gateway. BYO calls
     * are metered at zero cost (the user pays the provider directly), so a
     * paid plan is the entry requirement instead of the cost budget.
     */
    public const BYO_ALLOWED_LEVELS = ['PRO', 'TEAM', 'BUSINESS', 'ADMIN'];

    /** Providers the Messages gateway can translate to. */
    private const GATEWAY_PROVIDERS = ['anthropic', 'openai', 'google', 'gemini'];

    /**
     * @param iterable<MessagesTranslatorInterface> $translators
     */
    public function __construct(
        private MessagesGatewayConfig $config,
        private MessagesModelResolver $modelResolver,
        private ModelRepository $modelRepository,
        private VisionModelResolver $visionModelResolver,
        private UserProviderKeyResolver $keyResolver,
        private RateLimitService $rateLimitService,
        private AnthropicPassthroughTranslator $anthropicPassthrough,
        private GatewayToolCatalog $toolCatalog,
        private GatewayToolLoop $toolLoop,
        private MessagesContextInjector $contextInjector,
        private CacheItemPoolInterface $cache,
        private MessageBusInterface $messageBus,
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

        $requestBody = $decoded;
        $bodyMutated = false;
        $visionHandling = GatewayToolCatalog::VISION_NONE;
        $visionRewrite = $this->maybeRewriteForVision($user, $resolved, $decoded);
        if (null !== $visionRewrite) {
            $resolved = $visionRewrite['resolved'];
            $visionHandling = $visionRewrite['vision'];
            if ($visionRewrite['mutated']) {
                $requestBody['model'] = $resolved['providerModelId'];
                $bodyMutated = true;
            }
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

        if ('user' === $credential['source']) {
            // BYO key: metered at zero cost (the user pays the provider), so
            // the Synaplan budget does not apply — a paid plan is required instead.
            if (!\in_array($user->getRateLimitLevel(), self::BYO_ALLOWED_LEVELS, true)) {
                return $this->err(
                    403,
                    'permission_error',
                    'Using your own provider API key requires at least the Pro plan. Upgrade your Synaplan subscription to keep using BYO keys.',
                );
            }
        } elseif (!$budget['allowed']) {
            // Operator key: the install pays the provider, so the user's
            // Synaplan cost budget gates the request.
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

        // Alias rewrite: if the resolved provider model id differs from the
        // requested string, rewrite `model` so the upstream receives a real id.
        if ($resolved['providerModelId'] !== ($decoded['model'] ?? null)) {
            $requestBody['model'] = $resolved['providerModelId'];
            $bodyMutated = true;
        }

        $sessionId = $request->headers->get('x-claude-code-session-id');
        $sessionKey = $this->sessionKey($sessionId, $user, $requestBody);

        $toolCatalog = $this->toolCatalog->build($user, $sessionKey, $requestBody);
        $webSearch = $toolCatalog['web_search'];
        $toolLoop = [] !== $toolCatalog['tools'];
        $replacedServerTools = $this->toolCatalog->replacedServerTools($toolCatalog);
        if ($toolLoop) {
            // Injection happens inside GatewayToolLoop so the catalog is
            // applied once per upstream call; mark body mutated so the
            // raw-body fast path is disabled.
            $bodyMutated = true;
        } else {
            $toolCatalog = null;
            if ([] !== $replacedServerTools) {
                // Web search turned off: there is no loop to run, but the
                // declaration must still not reach the upstream.
                $requestBody = $this->toolLoop->stripServerTools($requestBody, $replacedServerTools);
                $bodyMutated = true;
            }
            $replacedServerTools = [];
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
            'raw_stream' => $translator instanceof AnthropicPassthroughTranslator && !$toolLoop,
            'usage' => new MessagesUsage(),
            'key_source' => $credential['source'],
            'resolved' => $resolved,
            'budget' => $budget,
            'session_id' => $sessionId,
            'session_key' => $sessionKey,
            'body_mutated' => $bodyMutated,
            'request_body' => $requestBody,
            'translator_context' => $translatorContext,
            'tool_loop' => $toolLoop,
            'tool_catalog' => $toolCatalog,
            'replaced_server_tools' => $replacedServerTools,
            'web_search' => $webSearch,
            'vision' => $visionHandling,
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
            if ($prepared['tool_loop'] && null !== $prepared['tool_catalog']) {
                $result = $this->toolLoop->runComplete(
                    $prepared['request_body'],
                    $prepared['translator_context'],
                    $translator,
                    $user,
                    $prepared['tool_catalog'],
                    $prepared['replaced_server_tools'],
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
            $this->dispatchSessionSummary($prepared, $user, $this->extractResponseText($body));
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

        if ($prepared['tool_loop'] && null !== $prepared['tool_catalog']) {
            $usage = $this->toolLoop->runStream(
                $prepared['request_body'],
                $prepared['translator_context'],
                $translator,
                $user,
                $prepared['tool_catalog'],
                $emit,
                $prepared['replaced_server_tools'],
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

    /**
     * When the turn carries images, either rewrite onto Synaplan's PIC2TEXT /
     * catalog vision model or leave the Anthropic-shaped request on the wire.
     *
     * @param array{
     *     provider: string,
     *     providerModelId: string,
     *     displayModel: string,
     *     model_id: int,
     *     requested: string,
     *     aliased_from: string|null
     * } $resolved
     * @param array<string, mixed> $decoded
     *
     * @return array{
     *     resolved: array{
     *         provider: string,
     *         providerModelId: string,
     *         displayModel: string,
     *         model_id: int,
     *         requested: string,
     *         aliased_from: string|null
     *     },
     *     vision: string,
     *     mutated: bool
     * }|null
     */
    private function maybeRewriteForVision(User $user, array $resolved, array $decoded): ?array
    {
        if (!$this->requestHasImages($decoded)) {
            return [
                'resolved' => $resolved,
                'vision' => GatewayToolCatalog::VISION_NONE,
                'mutated' => false,
            ];
        }

        $mode = $this->config->visionMode($user->getId());
        if (\in_array($mode, [MessagesGatewayConfig::VISION_PASSTHROUGH, MessagesGatewayConfig::VISION_OFF], true)) {
            return [
                'resolved' => $resolved,
                'vision' => GatewayToolCatalog::VISION_PASSTHROUGH,
                'mutated' => false,
            ];
        }

        $current = $this->modelRepository->find($resolved['model_id']);
        $supportsVision = $current instanceof Model && $current->hasFeature('vision');
        $wantSynaplan = MessagesGatewayConfig::VISION_SYNAPLAN === $mode
            || (MessagesGatewayConfig::VISION_AUTO === $mode && !$supportsVision);

        if (!$wantSynaplan) {
            return [
                'resolved' => $resolved,
                'vision' => GatewayToolCatalog::VISION_PASSTHROUGH,
                'mutated' => false,
            ];
        }

        $visionModel = $this->visionModelResolver->resolve($user->getId());
        if (!$visionModel instanceof Model) {
            $this->logger->info('MessagesGateway: no Synaplan vision model available, funneling images upstream', [
                'user_id' => $user->getId(),
                'mode' => $mode,
            ]);

            return [
                'resolved' => $resolved,
                'vision' => GatewayToolCatalog::VISION_PASSTHROUGH,
                'mutated' => false,
            ];
        }

        $provider = strtolower($visionModel->getService());
        if (!\in_array($provider, self::GATEWAY_PROVIDERS, true)) {
            $this->logger->info('MessagesGateway: vision model provider not supported by gateway, funneling images upstream', [
                'user_id' => $user->getId(),
                'provider' => $provider,
                'model_id' => $visionModel->getId(),
            ]);

            return [
                'resolved' => $resolved,
                'vision' => GatewayToolCatalog::VISION_PASSTHROUGH,
                'mutated' => false,
            ];
        }

        if ((int) $visionModel->getId() === $resolved['model_id']) {
            return [
                'resolved' => $resolved,
                'vision' => GatewayToolCatalog::VISION_PASSTHROUGH,
                'mutated' => false,
            ];
        }

        $providerModelId = $visionModel->getProviderId() ?: $visionModel->getName();
        $this->logger->info('MessagesGateway: rewriting image turn onto Synaplan vision model', [
            'user_id' => $user->getId(),
            'from_model_id' => $resolved['model_id'],
            'to_model_id' => $visionModel->getId(),
            'provider' => $provider,
            'mode' => $mode,
        ]);

        return [
            'resolved' => [
                'provider' => $provider,
                'providerModelId' => $providerModelId,
                'displayModel' => $providerModelId,
                'model_id' => (int) $visionModel->getId(),
                'requested' => $resolved['requested'],
                'aliased_from' => $resolved['aliased_from'],
            ],
            'vision' => GatewayToolCatalog::VISION_SYNAPLAN,
            'mutated' => true,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function requestHasImages(array $decoded): bool
    {
        $messages = $decoded['messages'] ?? null;
        if (!\is_array($messages)) {
            return false;
        }

        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (!\is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (\is_array($block) && 'image' === ($block['type'] ?? null)) {
                    return true;
                }
            }
        }

        return false;
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
        $inputText = $this->lastUserText($prepared['request_body']);
        $responseText = null !== $responseBody ? $this->extractResponseText($responseBody) : '';

        $metadata = [
            'provider' => $prepared['resolved']['provider'],
            'model' => $prepared['resolved']['providerModelId'],
            'model_id' => $prepared['resolved']['model_id'],
            'usage' => $usage->toRateLimitUsage(),
            'response_text' => $responseText,
            'input_text' => $inputText,
            'source' => 'MESSAGES_API',
            'key_source' => $prepared['key_source'],
        ];
        if ('user' === $prepared['key_source']) {
            // BYO key: the user pays the provider directly — meter tokens for
            // statistics but never charge the Synaplan budget.
            $metadata['zero_cost'] = true;
        }

        try {
            $this->rateLimitService->recordUsage($user, 'API_CHAT', $metadata);
        } catch (\Throwable $e) {
            $this->logger->error('MessagesGateway: recordUsage failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);
        }
    }

    /**
     * Queue the debounced per-session summary refresh (Channels → chat list
     * "what happened over the API" trail). Never blocks or fails the request
     * path; excerpts are capped before entering the queue payload.
     *
     * @param GatewaySuccess $prepared
     */
    public function dispatchSessionSummary(array $prepared, User $user, string $responseText): void
    {
        if (!$this->config->isSessionSummaryEnabled($user->getId())) {
            return;
        }

        $cap = ApiSessionSummaryService::EXCERPT_MAX_CHARS;

        try {
            $this->messageBus->dispatch(new SummarizeApiSessionCommand(
                userId: (int) $user->getId(),
                sessionKey: $prepared['session_key'],
                client: 'claude-code',
                model: $prepared['resolved']['displayModel'],
                requestExcerpt: mb_substr($this->lastUserText($prepared['request_body']), 0, $cap),
                responseExcerpt: mb_substr($responseText, 0, $cap),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('MessagesGateway: session summary dispatch failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);
        }
    }

    /**
     * Last user-turn text of an Anthropic-shaped request body.
     *
     * @param array<string, mixed> $requestBody
     */
    private function lastUserText(array $requestBody): string
    {
        $messages = $requestBody['messages'] ?? [];
        if (!\is_array($messages)) {
            return '';
        }

        foreach (array_reverse($messages) as $msg) {
            if (!\is_array($msg) || 'user' !== ($msg['role'] ?? '')) {
                continue;
            }
            $content = $msg['content'] ?? '';

            return \is_string($content) ? $content : (json_encode($content, \JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
        }

        return '';
    }

    /**
     * Concatenated text blocks of an Anthropic-shaped response body.
     *
     * @param array<string, mixed> $responseBody
     */
    private function extractResponseText(array $responseBody): string
    {
        $responseText = '';
        if (isset($responseBody['content']) && \is_array($responseBody['content'])) {
            foreach ($responseBody['content'] as $block) {
                if (\is_array($block) && 'text' === ($block['type'] ?? '') && isset($block['text'])) {
                    $responseText .= (string) $block['text'];
                }
            }
        }

        return $responseText;
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
