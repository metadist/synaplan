<?php

namespace App\Service\Message\Handler;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\AI\Stream\StreamChunk;
use App\AI\Stream\VisibleAnswer;
use App\AI\StructuredOutput\Schema\FileGenerationSchema;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\Document\ChatDocumentOutput;
use App\Service\Exception\VisionModelRequiredException;
use App\Service\File\FileGenerationEnvelope;
use App\Service\File\UnreadSourceGuard;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Memory\ChatMemoryContextLoader;
use App\Service\Memory\PendingMemoryExtraction;
use App\Service\Message\ChatGenerationOptions;
use App\Service\Message\ChatMessageAssembler;
use App\Service\Message\ProgressCallback;
use App\Service\Message\Routing\RoutingDirective;
use App\Service\Message\Routing\RoutingHandoffResolver;
use App\Service\Message\SlashCommandReplies;
use App\Service\ModelConfigService;
use App\Service\Multitask\Plan\Capability;
use App\Service\Prompt\ChatPromptBlocks;
use App\Service\Prompt\ChatSystemPromptBuilder;
use App\Service\Prompt\LanguageDirectiveBuilder;
use App\Service\Prompt\PromptService;
use App\Service\RAG\ChatKnowledgeContextLoader;
use App\Service\RateLimitService;
use App\Service\Runtime\RuntimeProfile;
use App\Service\Vision\VisionAttachmentEncoder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Chat Handler - Normaler Konversations-Chat.
 *
 * Uses user-defined model from BCONFIG or falls back to global default
 */
#[AutoconfigureTag('app.message.handler')]
final readonly class ChatHandler implements MessageHandlerInterface
{
    /**
     * Models copy the old "[YmdHis]: " history prefix into their answer.
     * One leading stamp is removed so it never reaches the person.
     */
    private const ECHOED_HISTORY_STAMP = '/^\[\d{14}\]:\s*/';

    /** Processing source of a question delegated from a live voice call. */
    private const SOURCE_LIVE_VOICE = 'live_voice';

    /**
     * The live voice speaks this answer aloud while the person waits, so the
     * whole reply has to be generated before a single word is heard.
     */
    private const LIVE_VOICE_MAX_TOKENS = 600;

    private const LIVE_VOICE_REPLY_RULE = "\n\nThis answer is spoken aloud in a live voice conversation. "
        .'Answer in at most three short spoken sentences. No markdown, lists, tables, links, or emoji. '
        .'Reply in the language of the question.';

    public function __construct(
        private AiFacade $aiFacade,
        private PromptService $promptService,
        private ModelConfigService $modelConfigService,
        private ModelRepository $modelRepository,
        private EntityManagerInterface $em,
        private RateLimitService $rateLimitService,
        private StructuredOutputConfig $structuredOutputConfig,
        private ChatGenerationOptions $generationOptions,
        private ChatKnowledgeContextLoader $knowledge,
        private ChatMemoryContextLoader $memory,
        private ChatSystemPromptBuilder $systemPrompt,
        private VisionAttachmentEncoder $vision,
        private ChatMessageAssembler $messages,
        private ChatDocumentOutput $documents,
        private RoutingHandoffResolver $routing,
        private SlashCommandReplies $slashReplies,
        private PendingMemoryExtraction $pendingMemory,
        private LoggerInterface $logger,
        private ?UnreadSourceGuard $unreadSourceGuard = null,
    ) {
    }

    public function getName(): string
    {
        return 'chat';
    }

    /**
     * A thinking model that ran out of room must not be stored as a finished
     * reply. The step would show Done and the chat would say the answer was
     * never saved (#2264).
     */
    private function rejectCutOffAnswer(string $content, ?string $finishReason, string $provider, string $model): void
    {
        if (!VisibleAnswer::isUnusable($content, $finishReason)) {
            return;
        }

        if (VisibleAnswer::failedBecauseOutputWasCut($content, $finishReason)) {
            $this->logger->warning('ChatHandler: model output was cut off before a readable answer', [
                'provider' => $provider,
                'model' => $model,
                'finish_reason' => $finishReason,
            ]);

            throw new ProviderException('The model ran out of room before it finished the answer.', $provider, ['error_code' => 'max_tokens', 'model' => $model]);
        }

        $this->logger->warning('ChatHandler: Provider returned no visible streaming content', [
            'provider' => $provider,
            'model' => $model,
        ]);

        throw new ProviderException('The AI model returned an empty response. Please try again or select a different model.', $provider, ['model' => $model]);
    }

    /**
     * @param array<int, array{role: string, content: string}|Message> $thread
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options        forwarded by InferenceRouter (channel, disable_memories, …)
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $hint = $this->slashReplies->hint($classification);
        if (null !== $hint) {
            return [
                'content' => $hint,
                'metadata' => ['slash_hint' => true, 'provider' => 'none', 'model' => 'none'],
            ];
        }

        ProgressCallback::notify($progressCallback, 'generating', 'Generating response...');

        // Local PerfTimer keeps the shared helpers (memory + feedback
        // loaders) shape-compatible with the streaming path without
        // forcing every non-streaming caller to construct one.
        $perfTimer = new PerfTimer();

        [$classification, $profile] = $this->generationOptions->applyRuntimeProfile($classification, $options);

        $topic = $classification['topic'] ?? 'general';
        $language = $classification['language'] ?? 'en';

        $promptData = $this->promptService->getPromptWithMetadata($topic, $message->getUserId(), $language);
        $promptMetadata = $promptData['metadata'] ?? [];

        $searchResults = $classification['search_results'] ?? null;
        if (array_key_exists('search_results', $classification)) {
            unset($classification['search_results']);
        }

        [$ragGroupKey, $ragLimit, $ragMinScore] = $this->knowledge->settings($profile, $classification, $options);
        $isRagQuery = Capability::RagQuery->value === ($classification['intent'] ?? '');
        $agentScopes = $this->knowledge->assistantScopes($profile, $message->getUserId());
        $searchKnowledge = $this->knowledge->shouldSearch(
            $agentScopes,
            (string) $message->getText(),
            $classification,
            $options,
            $isRagQuery,
        );
        $ragContext = '';
        $ragSources = [];
        if ($searchKnowledge) {
            $loaded = $this->knowledge->load(
                $message,
                $topic,
                $ragGroupKey,
                $ragLimit,
                $ragMinScore,
                $agentScopes,
                true,
            );
            $ragContext = $loaded['context'];
            $ragSources = $loaded['sources'];
        }

        if ($isRagQuery && '' === $ragContext) {
            $empty = $this->slashReplies->docsNotFound($classification);

            return [
                'content' => $empty,
                'metadata' => ['rag_query' => true, 'rag_chunks' => 0, 'provider' => 'none', 'model' => 'none'],
            ];
        }

        // Issue #615: the non-streaming path (email / generic webhook)
        // used to skip memory loading entirely, so memories never
        // influenced replies for those channels and new memories were
        // never extracted from the conversation. Mirror the streaming
        // path so every channel benefits from the user's stored
        // memories + feedback corrections.
        $user = $this->em->getRepository(User::class)->find($message->getUserId());
        $resolveSharedVector = $this->memory->queryVectorResolver($message, $perfTimer);
        $resolveMemoryVector = $this->memory->memoryVectorResolver($message, $resolveSharedVector, $perfTimer);

        $memoriesResult = $this->memory->loadMemories(
            $message,
            $user,
            $options,
            $classification,
            $progressCallback,
            $resolveMemoryVector,
            $perfTimer,
        );
        $memoriesContext = $memoriesResult['context'];
        $loadedMemories = $memoriesResult['memories'];
        $memoriesDisabledByRequest = $memoriesResult['disabledByRequest'];
        $memoriesDisabledByUser = $memoriesResult['disabledByUser'];
        $memoriesRequestDisableContext = $memoriesResult['requestDisableContext'];

        $feedbackResult = $this->memory->loadFeedback(
            $message,
            $user,
            $options,
            $classification,
            $progressCallback,
            $resolveMemoryVector,
            $perfTimer,
        );
        $feedbackContext = $feedbackResult['context'];
        $loadedFeedbacks = $feedbackResult['feedbacks'];

        $digestResult = $this->memory->loadDigests(
            $message,
            $thread,
            $user,
            $options,
            $classification,
            $progressCallback,
            $resolveMemoryVector,
            $perfTimer,
            promptIncludesFileText: false,
        );
        $digestContext = $digestResult['context'];
        $loadedDigests = $digestResult['digests'];

        // Determine model: Again > Widget config override > Prompt Metadata > DB default
        $modelId = null;
        $provider = null;
        $modelName = null;
        $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);

        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = (int) $classification['model_id'];
            $this->logger->info('ChatHandler: Using user-selected model (Again)', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
            ]);
        } elseif (isset($classification['override_model_id']) && (int) $classification['override_model_id'] > 0) {
            $modelId = (int) $classification['override_model_id'];
            $this->logger->info('ChatHandler: Using widget config model override', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
            ]);
        } elseif ($profile instanceof RuntimeProfile && isset($profile->modelIds['chat']) && $profile->modelIds['chat']) {
            $modelId = (int) $profile->modelIds['chat'];
            $this->logger->info('ChatHandler: Using runtime profile chat model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
            ]);
        } elseif (isset($promptMetadata['aiModel']) && (int) $promptMetadata['aiModel'] > 0) {
            $modelId = (int) $promptMetadata['aiModel'];
            $this->logger->info('ChatHandler: Using prompt metadata model', [
                'model_id' => $modelId,
                'topic' => $topic,
                'user_id' => $message->getUserId(),
            ]);
        } else {
            $modelId = $this->modelConfigService->getDefaultModel('CHAT', $effectiveUserId);
            $this->logger->info('ChatHandler: Using DB default model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
                'effective_user_id' => $effectiveUserId,
            ]);
        }

        $modelId = $this->degradeToUsableModel($modelId, $effectiveUserId, $message);

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);

            $this->logger->info('ChatHandler: Resolved model', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName,
            ]);
        }

        // Check if message has images and current model supports vision (non-streaming)
        $hasImages = $this->vision->hasAttachedImages($message);
        $includeImagesInMessages = false;

        if ($hasImages) {
            $currentModel = $modelId ? $this->modelRepository->find($modelId) : null;
            $supportsVision = $currentModel && $currentModel->hasFeature('vision');

            if ($supportsVision) {
                $includeImagesInMessages = true;
                $this->logger->info('ChatHandler: Current model supports vision, including images (non-streaming)');
            } else {
                $this->logger->info('ChatHandler: Message has images but model does not support vision, resolving configured vision model (non-streaming)');

                // Prefer the account's configured image model (PIC2TEXT); only
                // fall back to the global catalog pick when none is usable.
                $visionModel = $this->vision->fallbackModel($effectiveUserId);

                if ($visionModel) {
                    $modelId = $visionModel->getId();
                    $provider = strtolower($visionModel->getService());
                    $modelName = $visionModel->getProviderId();
                    $includeImagesInMessages = true;

                    $this->logger->info('ChatHandler: Switched to vision-capable model (non-streaming)', [
                        'model_id' => $modelId,
                        'provider' => $provider,
                        'model' => $modelName,
                        'model_name' => $visionModel->getName(),
                    ]);
                } else {
                    $this->logger->warning('ChatHandler: No vision-capable model found, images will not be analyzed (non-streaming)');
                }
            }
        }

        // Phase 9: the classifier deferred the routing decision to this call.
        // Resolved AFTER the vision swap above, because that swap can replace
        // $provider/$modelName with a model that cannot do tool calling — tools
        // built for the original model would then be dropped by the translator
        // and the hand-off would silently never happen. Still before any
        // context is built, so an unhonourable deferral costs nothing but a
        // re-route. The streaming twin resolves it at the same point.
        $routingDeferred = RoutingHandoffResolver::isDeferred($classification);
        $routingTools = $routingDeferred ? $this->routing->tools($provider, $modelName, false) : null;
        if ($routingDeferred && null === $routingTools) {
            return RoutingDirective::reclassify()->toHandlerResult();
        }

        $options['include_generated_images'] = $this->vision->shouldIncludeGeneratedImages($modelId, $effectiveUserId);

        $systemPrompt = 'You are the Synaplan.com AI assistant. Please answer in the language of the user.';
        if ($profile instanceof RuntimeProfile && null !== $profile->systemPrompt && '' !== $profile->systemPrompt) {
            $systemPrompt = $profile->systemPrompt;
            $this->logger->info('ChatHandler: Using runtime profile system prompt', [
                'topic' => $topic,
                'prompt_length' => strlen($systemPrompt),
            ]);
        } elseif ($promptData && isset($promptData['prompt'])) {
            $systemPrompt = $promptData['prompt']->getPrompt();
            $this->logger->info('ChatHandler: Using custom prompt content', [
                'topic' => $topic,
                'prompt_length' => strlen($systemPrompt),
            ]);
        }

        $docsList = [];
        $decorated = $this->systemPrompt->decorateSelfAware($systemPrompt, $topic, $message, $classification, $options, $progressCallback, $modelId);
        $systemPrompt = $this->documents->withPdfExportHint($decorated['prompt'], $topic);
        $systemPrompt = $this->documents->withToolsHint($systemPrompt, $topic, $modelId, $message);
        $docsList = $decorated['docs'];

        if (!empty($ragContext)) {
            $systemPrompt .= $ragContext;
            if (!empty($classification['slash_docs'])) {
                $systemPrompt .= ChatPromptBlocks::docsCitationInstruction();
            }
            $this->logger->info('ChatHandler: RAG context appended to system prompt', [
                'topic' => $topic,
                'rag_context_length' => strlen($ragContext),
            ]);
        }

        // Memories + feedback go between RAG and plugin/URL context so
        // their ordering matches the streaming path. This keeps prompt
        // shape consistent across Web UI and email/webhook channels
        // (issue #615).
        if (!empty($memoriesContext)) {
            $systemPrompt .= $memoriesContext;
            $this->logger->info('ChatHandler: Memories context appended to system prompt', [
                'memories_count' => count($loadedMemories),
                'memories_context_length' => strlen($memoriesContext),
            ]);
        }

        if (!empty($feedbackContext)) {
            $systemPrompt .= $feedbackContext;
            $this->logger->info('ChatHandler: Feedback context appended to system prompt', [
                'feedback_context_length' => strlen($feedbackContext),
            ]);
        }

        // Deep-memory digest block (references to key messages from older
        // conversations) — same position as the streaming path.
        if (!empty($digestContext)) {
            $systemPrompt .= $digestContext;
            $this->logger->info('ChatHandler: Digest context appended to system prompt', [
                'digest_count' => count($loadedDigests),
                'digest_context_length' => strlen($digestContext),
            ]);
        }

        // Append the rolling conversation summary exactly like handleStream()
        // does, so long email / MCP / webhook threads keep their topic and the
        // user's position while the verbatim thread stays inside the window
        // (channel parity — the non-streaming half of issue #615's promise).
        $conversationSummary = $options['conversation_summary'] ?? '';
        if (is_string($conversationSummary) && '' !== trim($conversationSummary)) {
            $systemPrompt .= ChatPromptBlocks::conversationSummary($conversationSummary);
            $this->logger->info('ChatHandler: Conversation summary appended to system prompt', [
                'summary_length' => \strlen($conversationSummary),
            ]);
        }

        // Append plugin context (external data sources like casting platforms)
        $systemPrompt = $this->systemPrompt->appendPluginContext($systemPrompt, $message, $classification, [
            'channel' => $classification['source'] ?? null,
        ]);

        $urlContent = $classification['url_content'] ?? null;
        if (is_string($urlContent) && '' !== $urlContent) {
            $systemPrompt .= "\n\n".$urlContent;
            $this->logger->info('ChatHandler: URL content appended to system prompt', [
                'url_content_length' => strlen($urlContent),
            ]);
        }

        $systemPrompt .= $this->systemPrompt->conversationFileContext($message, $thread);

        // Append explicit language directive based on detected language from classification.
        // Built via LanguageDirectiveBuilder so the wording stays consistent
        // across handlers and includes the anti-echo clause that prevents
        // smaller LLMs from leaking the directive back into the response
        // (e.g. "[Please reply in German]").
        // Re-appended AFTER web-search injection below so the English results
        // wrapper does not become the last system instruction.
        $languageDirective = 'auto' === $language
            ? LanguageDirectiveBuilder::buildAutoDirective()
            : LanguageDirectiveBuilder::buildForLanguage($language);
        $systemPrompt .= $languageDirective;

        // Country-only location awareness from the Cloudflare CF-IPCountry header.
        $systemPrompt .= ChatPromptBlocks::locationContext($options);

        // Current date/time: profile timezone → unambiguous country → server.
        $systemPrompt .= $this->systemPrompt->timeContext($user, $options);

        // Images this document may embed (officemaker only).
        $systemPrompt .= $this->systemPrompt->documentImageContext($message, $thread, $topic, $options);

        // The picture this turn edits (mediamaker only).
        $systemPrompt .= ChatPromptBlocks::mediaEditContext($topic, $options);

        // A document is written into the file, not spoken, so it keeps its
        // full length. The live voice only announces that it is in the chat.
        $liveVoice = self::SOURCE_LIVE_VOICE === ($options['source'] ?? null) && 'officemaker' !== $topic;
        if ($liveVoice) {
            $systemPrompt .= self::LIVE_VOICE_REPLY_RULE;
        }

        $modelMaxTokens = null;
        $systemPromptFallback = null;
        $loadedModel = null;
        if ($modelId) {
            $model = $this->modelRepository->find($modelId);
            if ($model) {
                $loadedModel = $model;
                $modelMaxTokens = $model->getMaxTokens();
                $json = $model->getJson();
                if (!$this->messages->supportsSystemMessages($json)) {
                    // Model rejects the `system` role: carry the prompt into the
                    // first user turn instead of dropping it (dropping discards
                    // the whole topic prompt — broke officemaker on GPT-5.5 Pro).
                    $systemPromptFallback = $systemPrompt;
                    $systemPrompt = null;
                }
            }
        }

        // Web search results go into the SYSTEM role — same rationale as the
        // streaming path (issue #1067). User-message fallback only for models
        // without system-message support.
        if (null !== $systemPrompt && is_array($searchResults) && !empty($searchResults['results'])) {
            $systemPrompt .= ChatPromptBlocks::webSearchResults($searchResults);
            $systemPrompt .= $languageDirective;
            $this->logger->info('ChatHandler: Web search context appended to system prompt', [
                'results_count' => count($searchResults['results']),
                'query' => $searchResults['query'] ?? '',
                'language' => $language,
            ]);
            $searchResults = null;
        }

        // Quoted reference ("Mention in chat") — injected after the capability
        // check so it lands in the SYSTEM role when supported, and falls back to
        // the user turn (via buildMessages) for models without system messages.
        if (null !== $systemPrompt && !empty($options['quoted_text'])) {
            $systemPrompt .= ChatPromptBlocks::quotedReference($options);
            $this->logger->info('ChatHandler: Quoted reference appended to system prompt (non-streaming)', [
                'quoted_message_id' => $options['quoted_message_id'] ?? null,
            ]);
            unset($options['quoted_text'], $options['quoted_message_id']);
        }

        if ($hasImages && !$includeImagesInMessages) {
            throw new VisionModelRequiredException();
        }

        $messages = $this->messages->buffered($systemPrompt, $thread, $message, [
            'search_results' => $searchResults,
            'rag_context' => $ragContext,
            'include_images' => $includeImagesInMessages,
            'include_generated_images' => $options['include_generated_images'],
            'quoted_text' => $options['quoted_text'] ?? null,
            'quoted_message_id' => $options['quoted_message_id'] ?? null,
            'attachment_text' => $this->messages->fitAttachmentText($message, $modelId, null),
            'plain_user_turn' => $liveVoice,
        ]);

        if (null !== $systemPromptFallback) {
            $messages = $this->messages->prependSystemPrompt($messages, $systemPromptFallback);
        }

        $aiOptions = [
            'provider' => $provider,
            'model' => $modelName,
            'stream' => false,
            'temperature' => 0.7,
        ];

        // officemaker is the only topic whose reply IS the machine-readable
        // envelope {"BFILEPATH":…,"BFILETEXT":…} — every other topic keeps
        // its free-form chat completion untouched.
        if ('officemaker' === $topic && $this->structuredOutputConfig->isEnabled($message->getUserId())) {
            $aiOptions['structured_output'] = FileGenerationSchema::build();
        }

        if (null !== $routingTools) {
            $aiOptions = array_merge($aiOptions, $routingTools);
        }

        // Clamp max_tokens to min(plan_limit, model_max).
        // plan_limit is only set for ANONYMOUS (the only tier with a hard
        // MAX_OUTPUT_TOKENS cap); for all authenticated tiers getMaxOutputTokens()
        // returns null, so the model's full max_tokens is used. Spend for those
        // tiers is bounded by the cost-budget gate / message-count limits instead.
        // Reuse the User entity loaded earlier for memories/feedback so
        // we don't hit the repository twice per request.
        $planMaxTokens = null !== $user ? $this->rateLimitService->getMaxOutputTokens($user) : null;
        $tokenLimits = array_filter(
            [$planMaxTokens, $modelMaxTokens, $liveVoice ? self::LIVE_VOICE_MAX_TOKENS : null],
            static fn ($v) => is_int($v) && $v > 0,
        );

        if (!empty($tokenLimits)) {
            $aiOptions['max_tokens'] = min($tokenLimits);
        }

        $aiOptions = $this->generationOptions->applyProfileGenerationOptions($aiOptions, $profile, $message->getUserId());
        $aiOptions = $this->generationOptions->applyReasoningLevel($aiOptions, $loadedModel, $options);
        $aiOptions = $this->generationOptions->applyLocalThinkingBudget($aiOptions);

        $documentEdit = $this->documents->tryToolsEdit(
            $topic,
            $modelId,
            $messages,
            $message,
            $aiOptions,
            $progressCallback,
            !empty($options['incognito']),
        );

        $heartbeat = $options['heartbeat'] ?? null;
        if (null !== $documentEdit) {
            $response = [
                'content' => $documentEdit->content,
                'provider' => $provider ?? 'unknown',
                'model' => $modelName ?? 'unknown',
                'usage' => $documentEdit->usage,
                'response_id' => null,
            ];
        } elseif (is_callable($heartbeat)) {
            // Channels without a stream (Telegram) still need a live signal
            // while the model works. Collect the same text chat() would return.
            $collected = '';
            $streamFinishReason = null;
            $streamMeta = $this->aiFacade->chatStream(
                $messages,
                function (string|array $chunk) use (&$collected, &$streamFinishReason, $heartbeat): void {
                    if (is_array($chunk) && 'finish' === ($chunk['type'] ?? null) && is_string($chunk['finish_reason'] ?? null)) {
                        $streamFinishReason = $chunk['finish_reason'];
                    }
                    $collected .= StreamChunk::visibleText($chunk);
                    $heartbeat();
                },
                $message->getUserId(),
                $aiOptions,
            );
            $response = [
                'content' => $collected,
                'provider' => $streamMeta['provider'] ?? ($provider ?? 'unknown'),
                'model' => $streamMeta['model'] ?? ($modelName ?? 'unknown'),
                'usage' => $streamMeta['usage'] ?? [],
                'response_id' => $streamMeta['response_id'] ?? null,
                'tool_calls' => $streamMeta['tool_calls'] ?? [],
                'finish_reason' => is_string($streamMeta['finish_reason'] ?? null) && '' !== $streamMeta['finish_reason']
                    ? $streamMeta['finish_reason']
                    : $streamFinishReason,
            ];
        } else {
            $response = $this->aiFacade->chat(
                $messages,
                $message->getUserId(),
                $aiOptions
            );
        }

        // Only the plain chat call above can carry a hand-off: the document
        // tool loop answers the officemaker topic, which is never deferred.
        if ($routingDeferred) {
            $handoff = $this->routing->directiveFor($response['tool_calls'] ?? []);
            if (null !== $handoff) {
                // The model asked for a different backend, so whatever text it
                // produced alongside the call is a preamble, not an answer.
                return $handoff->toHandlerResult();
            }
        }

        ProgressCallback::notify($progressCallback, 'generating', 'Response generated.');

        // Extract structured data from JSON response if present
        $content = $response['content'];
        $metadata = [
            'provider' => $response['provider'] ?? 'unknown',
            'model' => $response['model'] ?? 'unknown',
            'usage' => $response['usage'] ?? [],
            'response_id' => $response['response_id'] ?? null,
        ];
        if (null !== $documentEdit) {
            $content = $this->documents->applyEditResult($documentEdit, $message, $metadata);
        }

        if (is_string($content)) {
            $nonStreamFinish = is_string($response['finish_reason'] ?? null) ? $response['finish_reason'] : null;
            $this->rejectCutOffAnswer($content, $nonStreamFinish, (string) ($metadata['provider'] ?? 'unknown'), (string) ($metadata['model'] ?? 'unknown'));
        }

        if (is_string($content)) {
            $unstamped = preg_replace(self::ECHOED_HISTORY_STAMP, '', $content, 1);
            if (is_string($unstamped)) {
                $content = $unstamped;
            }
        }

        // Check for file generation format first (for OfficeM maker)
        $fileData = $this->documents->fileGenerationData($content);
        $refusal = null !== $fileData && null !== $this->unreadSourceGuard
            ? $this->unreadSourceGuard->refusalFor(
                (string) $message->getText(),
                $classification['url_pages_read'] ?? null,
                $classification['language'] ?? 'en',
            )
            : null;
        if (null !== $refusal) {
            // A named source URL went unread: honest refusal instead of an
            // invented document — same guard as DocumentGenerationRunner,
            // which single-node plans bypass via the legacy router (#2050).
            $this->logger->info('ChatHandler: refusing file generation, unread source URL');
            $content = $refusal;
            $metadata['document_generation'] = ['created' => false, 'reason' => 'source_unread'];
        } elseif (null !== $fileData) {
            $this->logger->info('ChatHandler: Detected AI file generation');

            // Store the file (ephemeral in incognito mode so it is cleaned up
            // after the session)
            $bundle = $this->documents->storeDocument($fileData, $message, !empty($options['incognito']));

            if ($bundle) {
                // Attach file(s) to message (in-memory only for transient
                // incognito messages — flush() never touches unmanaged entities)
                foreach ($bundle->files() as $generatedFile) {
                    $message->addFile($generatedFile);
                }
                $this->em->flush();

                $primary = $bundle->primary();
                $content = "__FILE_GENERATED__:{$primary->getFileName()}";

                $metadata['generated_file'] = [
                    'id' => $primary->getId(),
                    'filename' => $primary->getFileName(),
                    'path' => $primary->getFilePath(),
                    'size' => $primary->getFileSize(),
                    'type' => $primary->getFileType(),
                ];

                $this->logger->info('ChatHandler: File generation successful', [
                    'file_id' => $primary->getId(),
                    'filename' => $primary->getFileName(),
                ]);
            } else {
                $content = '__FILE_GENERATION_FAILED__';
                $this->logger->error('ChatHandler: File generation failed');
            }
        }
        // Non-streaming channels must follow the same no-leak contract as the
        // web stream. A malformed officemaker envelope cannot create a file,
        // but its raw document body must never become the assistant response.
        elseif ('officemaker' === $topic
            && is_string($content)
            && FileGenerationEnvelope::hasSignature($content)) {
            $this->logger->warning('ChatHandler: officemaker reply carried an unparseable file envelope; suppressing raw blob', [
                'text_length' => strlen($content),
            ]);
            $content = '__FILE_GENERATION_FAILED__';
        }
        // Legacy: Check for old JSON format (BTEXT, BFILE, BFILETEXT)
        // This is only for backward compatibility with old AI responses
        // New responses return plain text directly
        elseif (is_string($content) && str_starts_with(trim($content), '{')) {
            try {
                $jsonData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

                // Extract BTEXT as main content
                if (isset($jsonData['BTEXT'])) {
                    $content = $jsonData['BTEXT'];
                    $this->logger->info('ChatHandler: Extracted BTEXT from legacy JSON response');
                }

                // Extract file information (legacy format)
                if (!empty($jsonData['BFILE']) && !empty($jsonData['BFILETEXT'])) {
                    $metadata['file'] = [
                        'path' => $jsonData['BFILETEXT'],
                        'type' => $this->documents->fileTypeOf($jsonData['BFILETEXT']),
                    ];
                    $this->logger->info('ChatHandler: Extracted file data from legacy format', $metadata['file']);
                }

                // Extract web search results/links
                if (!empty($jsonData['BLINKS'])) {
                    $metadata['links'] = $jsonData['BLINKS'];
                    $this->logger->info('ChatHandler: Extracted links');
                }
            } catch (\JsonException $e) {
                // Not valid JSON, use content as-is
                $this->logger->debug('ChatHandler: Response not JSON', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Issue #615: extract new memories from the email/webhook
        // exchange just like the streaming path does. We pass the final
        // assistant text (post JSON unwrap, post file-generation marker)
        // so the worker reasons about what the user actually saw.
        //
        // Issue #881: callers that persist a separate outgoing assistant
        // message AFTER `handle()` returns (e.g. StreamController) MUST
        // pass `defer_memory_extraction = true` so the dispatch happens
        // after the outgoing flush. Otherwise the worker can run before
        // the OUT row exists and `writeOutcomeMeta()` writes the meta to
        // the IN row only — the frontend then polls the OUT id forever
        // and the memory toast never appears.
        $extractionResponseText = is_string($content) ? $content : '';
        $deferExtraction = !empty($options['defer_memory_extraction']);
        $extractionPayload = $this->pendingMemory->build(
            $message,
            $extractionResponseText,
            $thread,
            $loadedMemories,
            $memoriesDisabledByRequest,
            $memoriesDisabledByUser,
            $memoriesRequestDisableContext,
            !empty($options['incognito']),
        );

        if (!$deferExtraction) {
            $this->pendingMemory->dispatch($extractionPayload);
        }

        return [
            'content' => $content,
            'metadata' => array_merge($metadata, [
                'model_id' => $modelId, // Include resolved model_id for storage
                'memories' => $loadedMemories,
                'feedbacks' => $loadedFeedbacks,
                'digests' => $loadedDigests,
                'docs' => $docsList,
                'rag_sources' => $ragSources,
                'extraction_payload' => $deferExtraction ? $extractionPayload : null,
            ]),
        ];
    }

    /**
     * Handle with streaming support.
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $hint = $this->slashReplies->hint($classification);
        if (null !== $hint) {
            $streamCallback($hint);

            return [
                'metadata' => ['slash_hint' => true, 'provider' => 'none', 'model' => 'none'],
            ];
        }

        ProgressCallback::notify($progressCallback, 'analyzing_prompt', 'Analyzing the prompt...');

        $perfTimer = $options['perf_timer'] ?? null;
        if (!$perfTimer instanceof PerfTimer) {
            $perfTimer = new PerfTimer();
        }

        [$classification, $profile] = $this->generationOptions->applyRuntimeProfile($classification, $options);

        // Load prompt WITH metadata based on topic from classification.
        // Phase 1b: reuse the bundle that MessageProcessor already resolved when present.
        $topic = $classification['topic'] ?? 'general';
        if (isset($options['resolved_prompt_data']) && is_array($options['resolved_prompt_data'])) {
            $promptData = $options['resolved_prompt_data'];
        } else {
            $perfTimer->start('prompt_chathandler');
            $promptData = $this->promptService->getPromptWithMetadata($topic, $message->getUserId(), $classification['language'] ?? 'en');
            $perfTimer->stop('prompt_chathandler');
        }

        $promptMetadata = $promptData['metadata'] ?? [];

        $this->logger->info('ChatHandler: Loaded prompt metadata', [
            'topic' => $topic,
            'metadata' => $promptMetadata,
            'user_id' => $message->getUserId(),
        ]);

        // Phase 1a: embed the user query exactly once and reuse the vector
        // across RAG + memory + feedback searches. The embedding HTTP call
        // dominates per-search latency (50-250 ms each) — paying it 4× was
        // the biggest pre-LLM time sink before this change.
        //
        // Lazy-evaluated via a closure so requests with no text (e.g. file-
        // only) or with memories disabled never trigger the embed. The
        // closure is now shared with `handle()` so email/webhook callers
        // benefit from the same caching behaviour (issue #615).
        $resolveSharedVector = $this->memory->queryVectorResolver($message, $perfTimer);

        [$ragGroupKey, $ragLimit, $ragMinScore] = $this->knowledge->settings($profile, $classification, $options);
        $agentScopes = $this->knowledge->assistantScopes($profile, $message->getUserId());
        $isRagQuery = Capability::RagQuery->value === ($classification['intent'] ?? '');

        $searchKnowledge = $this->knowledge->shouldSearch(
            $agentScopes,
            (string) $message->getText(),
            $classification,
            $options,
            $isRagQuery,
        );

        $knowledge = $this->knowledge->loadForStream(
            $message,
            $topic,
            $ragGroupKey,
            $ragLimit,
            $ragMinScore,
            $agentScopes,
            $searchKnowledge,
            $isRagQuery,
            $resolveSharedVector,
            $perfTimer,
            $progressCallback,
        );
        $ragContext = $knowledge['context'];
        $ragSources = $knowledge['sources'];

        if ($isRagQuery && '' === $ragContext) {
            $empty = $this->slashReplies->docsNotFound($classification);
            $streamCallback($empty);

            return [
                'metadata' => ['rag_query' => true, 'rag_chunks' => 0, 'provider' => 'none', 'model' => 'none'],
            ];
        }

        // Memory + feedback context now live in shared helpers so the
        // non-streaming `handle()` (email/webhook) renders identical
        // prompts (issue #615). User entity load is needed up-front for
        // both helpers and for the rate-limit clamp further down.
        $user = $this->em->getRepository(User::class)->find($message->getUserId());
        $resolveMemoryVector = $this->memory->memoryVectorResolver($message, $resolveSharedVector, $perfTimer);

        $memoriesResult = $this->memory->loadMemories(
            $message,
            $user,
            $options,
            $classification,
            $progressCallback,
            $resolveMemoryVector,
            $perfTimer,
        );
        $memoriesContext = $memoriesResult['context'];
        $loadedMemories = $memoriesResult['memories'];
        $memoriesDisabledByRequest = $memoriesResult['disabledByRequest'];
        $memoriesDisabledByUser = $memoriesResult['disabledByUser'];
        $memoriesRequestDisableContext = $memoriesResult['requestDisableContext'];

        $feedbackResult = $this->memory->loadFeedback(
            $message,
            $user,
            $options,
            $classification,
            $progressCallback,
            $resolveMemoryVector,
            $perfTimer,
        );
        $feedbackContext = $feedbackResult['context'];
        $loadedFeedbacks = $feedbackResult['feedbacks'];

        $digestResult = $this->memory->loadDigests(
            $message,
            $thread,
            $user,
            $options,
            $classification,
            $progressCallback,
            $resolveMemoryVector,
            $perfTimer,
            promptIncludesFileText: true,
        );
        $digestContext = $digestResult['context'];
        $loadedDigests = $digestResult['digests'];

        // Get model - Priority: Again > Widget config override > Prompt Metadata > DB default
        $modelId = null;
        $provider = null;
        $modelName = null;
        $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);

        // 1. Check if user explicitly selected a model (e.g., via "Again" function)
        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = $classification['model_id'];
            $this->logger->info('ChatHandler: Using user-selected model (Again)', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
            ]);
        }
        // 2. Check if widget config provides a specific model (from widget.config.aiModelId)
        elseif (isset($classification['override_model_id']) && $classification['override_model_id'] > 0) {
            $modelId = (int) $classification['override_model_id'];
            $this->logger->info('ChatHandler: Using widget config model override', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
            ]);
        }
        // 3. Assistant runtime profile (pinned agentId)
        elseif ($profile instanceof RuntimeProfile && isset($profile->modelIds['chat']) && $profile->modelIds['chat']) {
            $modelId = $profile->modelIds['chat'];
            $this->logger->info('ChatHandler: Using runtime profile chat model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
            ]);
        }
        // 4. Check if prompt metadata defines a model (and it's not AUTOMATED = -1)
        elseif (isset($promptMetadata['aiModel']) && $promptMetadata['aiModel'] > 0) {
            $modelId = $promptMetadata['aiModel'];
            $this->logger->info('ChatHandler: Using prompt metadata model', [
                'model_id' => $modelId,
                'topic' => $topic,
                'user_id' => $message->getUserId(),
            ]);
        }
        // 4. Fall back to user's default model from DB
        else {
            $modelId = $this->modelConfigService->getDefaultModel('CHAT', $effectiveUserId);
            $this->logger->info('ChatHandler: Using DB default model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
                'effective_user_id' => $effectiveUserId,
            ]);
        }

        $modelId = $this->degradeToUsableModel($modelId, $effectiveUserId, $message);

        // Check if message has images and current model supports vision
        $hasImages = $this->vision->hasAttachedImages($message);
        $includeImagesInMessages = false;

        if ($hasImages) {
            $currentModel = $modelId ? $this->modelRepository->find($modelId) : null;
            $supportsVision = $currentModel && $currentModel->hasFeature('vision');

            if ($supportsVision) {
                $includeImagesInMessages = true;
                $this->logger->info('ChatHandler: Current model supports vision, including images (streaming)');
            } else {
                $this->logger->info('ChatHandler: Message has images but model does not support vision, resolving configured vision model');

                // Prefer the account's configured image model (PIC2TEXT); only
                // fall back to the global catalog pick when none is usable.
                $visionModel = $this->vision->fallbackModel($effectiveUserId);

                if ($visionModel) {
                    $modelId = $visionModel->getId();
                    $includeImagesInMessages = true;

                    $this->logger->info('ChatHandler: Switched to vision-capable model for streaming', [
                        'model_id' => $modelId,
                        'provider' => strtolower($visionModel->getService()),
                        'model' => $visionModel->getProviderId(),
                        'model_name' => $visionModel->getName(),
                    ]);
                } else {
                    $this->logger->warning('ChatHandler: No vision-capable model found, images will not be analyzed');
                }
            }
        }

        $options['include_generated_images'] = $this->vision->shouldIncludeGeneratedImages($modelId, $effectiveUserId);

        // Simple system prompt for streaming (like old system)
        $systemPrompt = 'You are the Synaplan.com AI assistant. Please answer in the language of the user.';

        if ($profile instanceof RuntimeProfile && null !== $profile->systemPrompt && '' !== $profile->systemPrompt) {
            $systemPrompt = $profile->systemPrompt;
            $this->logger->info('ChatHandler: Using runtime profile system prompt', [
                'topic' => $topic,
                'prompt_length' => strlen($systemPrompt),
            ]);
        } elseif ($promptData && isset($promptData['prompt'])) {
            $systemPrompt = $promptData['prompt']->getPrompt();
            $this->logger->info('ChatHandler: Using custom prompt content', [
                'topic' => $topic,
                'prompt_length' => strlen($systemPrompt),
            ]);
        }

        $docsList = [];
        $decorated = $this->systemPrompt->decorateSelfAware($systemPrompt, $topic, $message, $classification, $options, $progressCallback, $modelId);
        $systemPrompt = $this->documents->withPdfExportHint($decorated['prompt'], $topic);
        $systemPrompt = $this->documents->withToolsHint($systemPrompt, $topic, $modelId, $message);
        $docsList = $decorated['docs'];

        // Append RAG context to system prompt if available
        if (!empty($ragContext)) {
            $systemPrompt .= $ragContext;
            if (!empty($classification['slash_docs'])) {
                $systemPrompt .= ChatPromptBlocks::docsCitationInstruction();
            }
            $this->logger->info('ChatHandler: RAG context appended to system prompt', [
                'topic' => $topic,
                'rag_context_length' => strlen($ragContext),
            ]);
        }

        // Append user memories context to system prompt if available
        if (!empty($memoriesContext)) {
            $systemPrompt .= $memoriesContext;
            $this->logger->info('ChatHandler: Memories context appended to system prompt', [
                'memories_count' => count($loadedMemories),
                'memories_context_length' => strlen($memoriesContext),
            ]);
        }

        // Append feedback examples context to system prompt if available
        if (!empty($feedbackContext)) {
            $systemPrompt .= $feedbackContext;
            $this->logger->info('ChatHandler: Feedback context appended to system prompt', [
                'feedback_context_length' => strlen($feedbackContext),
            ]);
        }

        // Deep-memory digest block (references to key messages from older
        // conversations) — same position as the non-streaming path.
        if (!empty($digestContext)) {
            $systemPrompt .= $digestContext;
            $this->logger->info('ChatHandler: Digest context appended to system prompt', [
                'digest_count' => count($loadedDigests),
                'digest_context_length' => strlen($digestContext),
            ]);
        }

        // Append the rolling conversation summary (older turns condensed by
        // MessageProcessor) so long threads keep their topic / the user's
        // position while the verbatim thread below stays inside the memory
        // window. Injected into the SYSTEM role like memories/feedback so it is
        // also carried into the first user turn for models without system-message
        // support (the o1 fallback below runs after this block).
        $conversationSummary = $options['conversation_summary'] ?? '';
        if (is_string($conversationSummary) && '' !== trim($conversationSummary)) {
            $systemPrompt .= ChatPromptBlocks::conversationSummary($conversationSummary);
            $this->logger->info('ChatHandler: Conversation summary appended to system prompt', [
                'summary_length' => \strlen($conversationSummary),
            ]);
        }

        // Append live API data from widget flow api-type responses
        $apiContext = $options['api_context'] ?? '';
        if ('' !== $apiContext) {
            $systemPrompt .= "\n\n".$apiContext;
            $this->logger->info('ChatHandler: API context appended to system prompt', [
                'api_context_length' => \strlen($apiContext),
            ]);
        }

        // Append plugin context (external data sources like casting platforms)
        $systemPrompt = $this->systemPrompt->appendPluginContext($systemPrompt, $message, $classification, $options);

        // Linked pages the processor read for this turn — the streaming path
        // must see them exactly like handle() does, otherwise a pasted link
        // is fetched and then silently dropped before the model answers.
        $urlContent = $classification['url_content'] ?? null;
        if (is_string($urlContent) && '' !== $urlContent) {
            $systemPrompt .= "\n\n".$urlContent;
            $this->logger->info('ChatHandler: URL content appended to streaming system prompt', [
                'url_content_length' => strlen($urlContent),
            ]);
        }

        $systemPrompt .= $this->systemPrompt->conversationFileContext($message, $thread);

        // Append explicit language directive based on detected language from classification.
        // The sort prompt detects the user's language (BLANG), but the system prompt only says
        // "answer in the user's language" without specifying WHICH language was detected.
        // When conversation history contains mixed languages, the AI may default to the wrong one.
        // Anti-echo clause inside the builder also prevents leakage like "[Please reply in German]".
        // Re-appended AFTER web-search injection below: the English search-results
        // block otherwise becomes the last system prose and steers answers to English.
        $detectedLanguage = $classification['language'] ?? 'en';
        $languageDirective = 'auto' === $detectedLanguage
            ? LanguageDirectiveBuilder::buildAutoDirective()
            : LanguageDirectiveBuilder::buildForLanguage($detectedLanguage);
        $systemPrompt .= $languageDirective;

        // Continuation mode: instruct the model to continue from where it left off
        if (!empty($options['is_continuation'])) {
            $systemPrompt .= "\n\n**CONTINUATION: Your previous response was cut off by the token limit. Continue EXACTLY where you left off. Do NOT repeat, summarize, or re-introduce anything you already said. Start immediately from the point where the text was cut off.**";
        }

        // Voice reply mode: enforce concise answers for TTS (spoken responses should be brief)
        if (!empty($options['voice_reply'])) {
            $systemPrompt .= "\n\n**VOICE MODE: Your response will be spoken aloud as audio. Keep your answer concise and conversational — maximum 4-5 sentences. Avoid markdown formatting, code blocks, bullet lists, and tables. Write in natural, flowing prose suitable for speech.**";
        }

        // Country-only location awareness from the Cloudflare CF-IPCountry header.
        $systemPrompt .= ChatPromptBlocks::locationContext($options);

        // Current date/time: profile timezone → unambiguous country → server.
        $systemPrompt .= $this->systemPrompt->timeContext($user, $options);

        // Images this document may embed (officemaker only).
        $systemPrompt .= $this->systemPrompt->documentImageContext($message, $thread, $topic, $options);

        // The picture this turn edits (mediamaker only).
        $systemPrompt .= ChatPromptBlocks::mediaEditContext($topic, $options);

        // Check if model supports system messages (o1 models don't)
        $systemPromptFallback = null;
        if ($modelId) {
            $model = $this->modelRepository->find($modelId);
            if ($model) {
                $json = $model->getJson();
                if (!$this->messages->supportsSystemMessages($json)) {
                    // Model rejects the `system` role: carry the prompt into the
                    // first user turn instead of dropping it (dropping discards
                    // the whole topic prompt — broke officemaker on GPT-5.5 Pro).
                    $systemPromptFallback = $systemPrompt;
                    $systemPrompt = null;
                }
            }
        }

        // Quoted reference ("Mention in chat"): the user selected an excerpt from
        // an earlier message as the focus of this request. Inject it into the
        // SYSTEM role for the same trust-boundary reasons as web search. When the
        // model has no system-message support the option stays set and
        // buildCurrentMessageContent() appends it to the user turn instead.
        if (null !== $systemPrompt && !empty($options['quoted_text'])) {
            $systemPrompt .= ChatPromptBlocks::quotedReference($options);
            $this->logger->info('ChatHandler: Quoted reference appended to system prompt', [
                'quoted_message_id' => $options['quoted_message_id'] ?? null,
            ]);
            unset($options['quoted_text'], $options['quoted_message_id']);
        }

        // Web search results belong to the SYSTEM role: injecting them into the
        // user turn makes the model attribute them to the user ("the user gave
        // web search results") and blurs the user/system trust boundary
        // (prompt-injection surface, issue #1067). Models without system-message
        // support keep the legacy user-message fallback in buildCurrentMessageContent().
        if (null !== $systemPrompt && isset($options['search_results']) && !empty($options['search_results']['results'])) {
            $systemPrompt .= ChatPromptBlocks::webSearchResults($options['search_results']);
            // Reinforce language AFTER the English search-results wrapper so the
            // model does not follow the trailing "Please use this information…"
            // English instruction into an English reply.
            $systemPrompt .= $languageDirective;
            $this->logger->info('ChatHandler: Web search context appended to system prompt', [
                'results_count' => count($options['search_results']['results']),
                'query' => $options['search_results']['query'] ?? '',
                'language' => $detectedLanguage,
            ]);
            unset($options['search_results']);
        }

        // Add include_images flag to options for message building
        $options['include_images'] = $includeImagesInMessages;

        if ($hasImages && !$includeImagesInMessages) {
            throw new VisionModelRequiredException();
        }

        // Resolve model ID to provider + model name + features (before building messages)
        $modelFeatures = [];
        $modelMaxTokens = null;
        $loadedModel = null;
        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);

            // Get model features and config from DB
            $model = $this->modelRepository->find($modelId);
            if ($model) {
                $loadedModel = $model;
                $modelFeatures = $model->getFeatures();
                $modelMaxTokens = $model->getMaxTokens();
            }

            $this->logger->info('ChatHandler: Resolved model for streaming', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName,
                'features' => $modelFeatures,
            ]);
        }

        // Phase 9 — see handle() for the non-streaming twin. The check has to
        // pass for the STREAMING capability here: a provider that returns tool
        // calls in a plain response but not in its SSE stream would swallow the
        // hand-off and answer with nothing.
        $routingDeferred = RoutingHandoffResolver::isDeferred($classification);
        $routingTools = $routingDeferred ? $this->routing->tools($provider, $modelName, true) : null;
        if ($routingDeferred && null === $routingTools) {
            return RoutingDirective::reclassify()->toHandlerResult();
        }

        // Load previous_response_id for OpenAI stateful conversations
        if ('openai' === $provider) {
            $previousResponseId = $this->messages->previousResponseId($thread);
            if (null !== $previousResponseId) {
                $options['previous_response_id'] = $previousResponseId;
                $this->logger->info('ChatHandler: Using previous_response_id for stateful conversation', [
                    'previous_response_id' => $previousResponseId,
                ]);
            }
        }

        // Build conversation history (TEXT only for streaming)
        $options['attachment_text'] = $this->messages->fitAttachmentText($message, $modelId, is_int($options['max_tokens'] ?? null) ? $options['max_tokens'] : null);
        $messages = $this->messages->streaming($systemPrompt, $thread, $message, $options);
        unset($options['attachment_text']);

        if (null !== $systemPromptFallback) {
            $messages = $this->messages->prependSystemPrompt($messages, $systemPromptFallback);
        }

        // Call AI streaming - merge processing options with model config
        $aiOptions = array_merge([
            'provider' => $provider,
            'model' => $modelName,
            'temperature' => 0.7,
            'modelFeatures' => $modelFeatures,
        ], $options);

        // officemaker is the only topic whose reply IS the machine-readable
        // envelope {"BFILEPATH":…,"BFILETEXT":…} — every other topic keeps
        // its free-form chat completion untouched.
        if ('officemaker' === $topic && $this->structuredOutputConfig->isEnabled($message->getUserId())) {
            $aiOptions['structured_output'] = FileGenerationSchema::build();
        }

        if (null !== $routingTools) {
            $aiOptions = array_merge($aiOptions, $routingTools);
        }

        // Clamp max_tokens to min(requested, plan_limit, model_max).
        // plan_limit is only set for ANONYMOUS; authenticated tiers get the
        // model's full max_tokens (getMaxOutputTokens() returns null for them).
        $planMaxTokens = null !== $user ? $this->rateLimitService->getMaxOutputTokens($user) : null;
        $tokenLimits = array_filter(
            [$aiOptions['max_tokens'] ?? null, $planMaxTokens, $modelMaxTokens],
            static fn ($v) => is_int($v) && $v > 0,
        );

        if (!empty($tokenLimits)) {
            $aiOptions['max_tokens'] = min($tokenLimits);
        }

        $aiOptions = $this->generationOptions->applyProfileGenerationOptions($aiOptions, $profile, $message->getUserId());
        $aiOptions = $this->generationOptions->applyReasoningLevel($aiOptions, $loadedModel, $options);
        $aiOptions = $this->generationOptions->applyLocalThinkingBudget($aiOptions);

        $this->logger->info('ChatHandler: Calling AiFacade chatStream', [
            'provider' => $provider,
            'model' => $modelName,
            'user_id' => $message->getUserId(),
            'reasoning' => $aiOptions['reasoning'] ?? false,
        ]);

        // Announced here, not at the top of the method: everything above
        // (knowledge-base lookup, memories, prompt assembly) reports its own
        // step, and claiming "generating" before them would show the last
        // stage first and then appear to go backwards. Carries the model that
        // is actually called (override / agent pin / topic default resolved),
        // so the progress timeline can say "Sending your request to X".
        ProgressCallback::notify($progressCallback, 'generating', 'Generating response...', [
            'provider' => $provider ?? null,
            'model_name' => $modelName ?? null,
            'model_id' => $modelId,
            'stage' => 'request_sent',
        ]);

        $fullResponseText = '';
        $sawFirstToken = false;
        $streamFinishReason = null;
        $wrappedStreamCallback = function (string|array $chunk, array $metadata = []) use ($streamCallback, &$fullResponseText, &$sawFirstToken, &$streamFinishReason, $perfTimer): void {
            if (is_array($chunk) && 'finish' === ($chunk['type'] ?? null) && is_string($chunk['finish_reason'] ?? null) && '' !== $chunk['finish_reason']) {
                $streamFinishReason = $chunk['finish_reason'];
            }
            // Mark TTFT on the very first chunk that carries any visible content.
            // This is the user-facing "first token" — what determines the perceived
            // wait between hitting send and seeing characters appear.
            if (!$sawFirstToken) {
                $hasContent = false;
                if (is_array($chunk)) {
                    $type = $chunk['type'] ?? null;
                    if (in_array($type, ['content', 'reasoning'], true)
                        && '' !== trim((string) ($chunk['content'] ?? ''))) {
                        $hasContent = true;
                    }
                } elseif ('' !== trim($chunk)) {
                    $hasContent = true;
                }

                if ($hasContent) {
                    $perfTimer->mark('provider_ttft');
                    $sawFirstToken = true;
                }
            }

            // Handle both string chunks (old providers) and array chunks (new providers with type/content)
            if (is_array($chunk)) {
                // Extract content from array format: ['type' => 'content', 'content' => '...']
                if (isset($chunk['type']) && 'content' === $chunk['type'] && isset($chunk['content'])) {
                    $fullResponseText .= $chunk['content'];
                }
            } else {
                // Old format: simple string
                $fullResponseText .= $chunk;
            }

            // Forward to original callback (always pass the chunk as-is)
            $streamCallback($chunk, $metadata);
        };

        $perfTimer->start('provider_total');
        $documentEdit = $this->documents->tryToolsEdit(
            $topic,
            $modelId,
            $messages,
            $message,
            $aiOptions,
            $progressCallback,
            !empty($options['incognito']),
        );
        if (null !== $documentEdit) {
            $metadata = [
                'provider' => $provider ?? 'unknown',
                'model' => $modelName ?? 'unknown',
                'usage' => $documentEdit->usage,
                'response_id' => null,
            ];
            $this->documents->applyEditResult($documentEdit, $message, $metadata);
            $wrappedStreamCallback($documentEdit->content);
        } else {
            $metadata = $this->aiFacade->chatStream(
                $messages,
                $wrappedStreamCallback, // Use wrapped callback
                $message->getUserId(),
                $aiOptions
            );
        }
        $perfTimer->stop('provider_total');

        if ($routingDeferred) {
            $handoff = $this->routing->directiveFor($metadata['tool_calls'] ?? []);
            if (null !== $handoff) {
                // Checked BEFORE the empty-stream guard below: a hand-off turn
                // legitimately streams no visible token, and raising "the model
                // returned an empty response" here would turn a successful
                // routing decision into a user-facing provider error.
                return $handoff->toHandlerResult();
            }
        }

        $finishReason = $streamFinishReason;
        if (is_string($metadata['finish_reason'] ?? null) && '' !== $metadata['finish_reason']) {
            $finishReason = $metadata['finish_reason'];
        }
        $responseProvider = is_string($metadata['provider'] ?? null)
            ? $metadata['provider']
            : ($provider ?? 'unknown');
        $responseModel = is_string($metadata['model'] ?? null)
            ? $metadata['model']
            : ($modelName ?? 'unknown');
        $this->rejectCutOffAnswer($fullResponseText, $finishReason, $responseProvider, $responseModel);

        if (!$sawFirstToken) {
            $this->logger->warning('ChatHandler: Provider returned no visible streaming content', [
                'provider' => $responseProvider,
                'model' => $responseModel,
                'user_id' => $message->getUserId(),
            ]);

            throw new ProviderException('The AI model returned an empty response. Please try again or select a different model.', $responseProvider, ['model' => $responseModel]);
        }

        $this->logger->info('ChatHandler: AiFacade chatStream returned', [
            'response_length' => strlen($fullResponseText),
        ]);

        // A distinct status: re-announcing `generating` after the last token
        // made the client show the spinner again below the finished answer.
        ProgressCallback::notify($progressCallback, 'generated', 'Response generated.');

        // Phase 2b: dispatch memory extraction to the messenger worker
        // instead of running it inline. This frees the SSE stream to send
        // `complete` immediately after the answer text is delivered, so
        // the user can type the next message without waiting 5-9 s for
        // the post-stream extraction LLM call + Qdrant writes. Same
        // helper is now shared with `handle()` so the email/webhook
        // channel also benefits (issue #615).
        //
        // Issue #881: when invoked from StreamController the dispatch is
        // deferred (`defer_memory_extraction` option) until after the
        // outgoing assistant message has been persisted and flushed.
        // Otherwise the worker can race the StreamController flush and
        // write the extracted-memories meta to the IN row only — the
        // frontend polls the OUT id and never sees `complete`, so no
        // toast appears. The dispatch payload is returned in `metadata`
        // so the StreamController can fire it after the flush.
        $deferExtraction = !empty($options['defer_memory_extraction']);
        $extractionPayload = $this->pendingMemory->build(
            $message,
            $fullResponseText,
            $thread,
            $loadedMemories,
            $memoriesDisabledByRequest,
            $memoriesDisabledByUser,
            $memoriesRequestDisableContext,
            !empty($options['incognito']),
        );

        if (!$deferExtraction) {
            $this->pendingMemory->dispatch($extractionPayload);
        }

        return [
            'metadata' => [
                'provider' => $metadata['provider'] ?? 'unknown',
                'model' => $metadata['model'] ?? 'unknown',
                'model_id' => $modelId,
                'usage' => $metadata['usage'] ?? [],
                'response_id' => $metadata['response_id'] ?? null,
                'generated_file' => $metadata['generated_file'] ?? null,
                'documentChanges' => $metadata['documentChanges'] ?? null,
                'documentVersion' => $metadata['documentVersion'] ?? null,
                'documentFidelityLossy' => $metadata['documentFidelityLossy'] ?? false,
                'memories' => $loadedMemories,
                'feedbacks' => $loadedFeedbacks,
                'digests' => $loadedDigests,
                'docs' => $docsList,
                'rag_sources' => $ragSources,
                'extraction_payload' => $deferExtraction ? $extractionPayload : null,
            ],
        ];
    }

    /**
     * Swap a model that can no longer serve for its recorded successor, or the
     * account default when the retirement recorded none.
     *
     * The three branches above the DB default all read a BID that was stored
     * elsewhere and earlier — a widget's `aiModelId`, a prompt's `aiModel`, the
     * model an older message was answered with when "Again" replays it. None of
     * them is rewritten when a model is retired, so the stored copy stays on the
     * dead BID. {@see ModelConfigService::resolveUsableModelId()} follows
     * BSUCCESSORID first; the capability default is the fallback for
     * `successor: null`.
     */
    private function degradeToUsableModel(?int $modelId, ?int $effectiveUserId, Message $message): ?int
    {
        $usableModelId = $this->modelConfigService->resolveUsableModelId($modelId, 'CHAT', $effectiveUserId);

        if ($usableModelId !== $modelId) {
            $this->logger->warning('ChatHandler: Configured model is no longer usable, falling back', [
                'configured_model_id' => $modelId,
                'model_id' => $usableModelId,
                'user_id' => $message->getUserId(),
                'effective_user_id' => $effectiveUserId,
            ]);
        }

        return $usableModelId;
    }
}
