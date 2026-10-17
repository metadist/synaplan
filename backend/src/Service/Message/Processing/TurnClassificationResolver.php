<?php

namespace App\Service\Message\Processing;

use App\Entity\Agent;
use App\Entity\Message;
use App\Service\Agent\AgentConfig;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\MessageClassifier;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use App\Service\Runtime\RuntimeProfile;
use Psr\Log\LoggerInterface;

/**
 * What a turn is about and which prompt answers it.
 *
 * Fixed-prompt turns (widget, API, Saved Task) and Again skip the AI sorter;
 * everything else is classified. The buffered and the streamed turn still
 * differ here — fixed-prompt language, RAG scope keys, prompt metadata, the
 * forced image description — so each difference has a buffered and a
 * streamed method of its own (code cleanup V3, master plan §3).
 */
final readonly class TurnClassificationResolver
{
    public function __construct(
        private MessageClassifier $classifier,
        private ModelConfigService $modelConfigService,
        private AgentConfig $agentConfig,
        private PromptService $promptService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function hasFixedPrompt(array $options): bool
    {
        return isset($options['fixed_task_prompt']) && !empty($options['fixed_task_prompt']);
    }

    /**
     * Drop the agent pin when the flag is off, or when a fixed prompt that is
     * not an assistant's owns the turn.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function pinAgent(Message $message, array $options): array
    {
        if (empty($options['agentId'])) {
            return $options;
        }
        $fixedTopic = $options['fixed_task_prompt'] ?? null;
        $assistantPrompt = is_string($fixedTopic) && str_starts_with($fixedTopic, Agent::TOPIC_PREFIX);
        if ((!$assistantPrompt && self::hasFixedPrompt($options)) || !$this->agentConfig->isEnabled($message->getUserId())) {
            unset($options['agentId'], $options['agentDraft']);
        }

        return $options;
    }

    /**
     * The `classifying` status, naming the sorting model.
     *
     * @return array{0: ?int, 1: ?string, 2: ?string} sorting model id, provider, name
     */
    public function announceSorting(Message $message, TurnStatus $status): array
    {
        $id = $this->modelConfigService->getDefaultModel('SORT', $message->getUserId());
        $sorting = [$id, $id ? $this->modelConfigService->getProviderForModel($id) : null, $id ? $this->modelConfigService->getModelName($id) : null];
        $status->report('classifying', 'Analyzing message intent...', ['model_id' => $sorting[0], 'provider' => $sorting[1], 'model_name' => $sorting[2]]);

        return $sorting;
    }

    /**
     * @param array<int, mixed>    $history
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function classify(Message $message, array $history, array $options): array
    {
        $classifyOptions = [];
        if (!empty($options['agentId'])) {
            $classifyOptions['agentId'] = (int) $options['agentId'];
        }
        if (array_key_exists('agentDraft', $options)) {
            $classifyOptions['agentDraft'] = (bool) $options['agentDraft'];
        }
        if (!empty($options['web_search']) || !empty($options['force_web_search'])) {
            $classifyOptions['web_search_requested'] = true;
        }

        return $this->classifier->classify($message, $history, null, true, $classifyOptions);
    }

    /**
     * The runtime profile is the one runtime seam: handlers read RAG scope,
     * limit and score from it, so nothing is copied into scalar options.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function afterClassify(array $classification, array &$options): array
    {
        $profile = $classification['runtime_profile'] ?? null;
        if ($profile instanceof RuntimeProfile) {
            $options['runtime_profile'] = $profile;
        }

        return $classification;
    }

    /**
     * The sorter's model leaves the classification (handlers must not answer
     * with it) unless an agent pin put the chat model there; a model picked in
     * the dropdown rides along as `override_model_id`; the `classified` status
     * narrates the verdict.
     *
     * @param array<string, mixed>                   $classification
     * @param array<string, mixed>                   $options
     * @param array{0: ?int, 1: ?string, 2: ?string} $sorting
     *
     * @return array{0: array<string, mixed>, 1: array{0: mixed, 1: mixed, 2: mixed}}
     */
    public function finish(array $classification, array $options, TurnStatus $status, array $sorting): array
    {
        if ('agent' !== ($classification['source'] ?? '')) {
            $sorting = [$classification['model_id'] ?? null, $classification['provider'] ?? null, $classification['model_name'] ?? null];
            unset($classification['model_id'], $classification['provider'], $classification['model_name']);
        }

        if (!empty($options['override_model_id'])) {
            $classification['override_model_id'] = (int) $options['override_model_id'];
        }

        $status->report('classified', sprintf(
            'Topic: %s, Language: %s, Source: %s',
            $classification['topic'],
            $classification['language'],
            $classification['source'],
        ), [
            'topic' => $classification['topic'],
            'language' => $classification['language'],
            'source' => $classification['source'],
            'sorting_model_id' => $sorting[0],
            'sorting_provider' => $sorting[1],
            'sorting_model_name' => $sorting[2],
            ...$this->summary($classification),
        ]);

        return [$classification, $sorting];
    }

    /**
     * Streamed: the language is the message's own (`auto` when unknown), so
     * the answer follows whatever the user writes.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function fixedPromptForStreamedTurn(Message $message, array $options, TurnStatus $status): array
    {
        [$isWidget, $source] = self::fixedPromptSource($options);
        $language = $message->getLanguage();
        if (!$language || 'NN' === $language) {
            $language = 'auto';
        }

        $classification = self::fixedPromptClassification($options, $language, $source, $isWidget);
        $this->logger->info('MessageProcessor: Using fixed prompt (skipped AI classification)', [
            'language' => $language,
            'fixed_topic' => $options['fixed_task_prompt'],
            'source' => $source,
            'widget_model_id' => $options['widget_model_id'] ?? null,
        ]);
        $status->report('classified', sprintf('%s: Using fixed prompt', ucfirst($source)));

        return $classification;
    }

    /**
     * Buffered: the language override from the caller, else `en`.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function fixedPromptForBufferedTurn(array $options, TurnStatus $status): array
    {
        [$isWidget, $source] = self::fixedPromptSource($options);
        $classification = self::fixedPromptClassification($options, $options['language'] ?? 'en', $source, $isWidget);
        $this->logger->info('MessageProcessor: Using fixed task prompt', ['task_prompt' => $options['fixed_task_prompt'], 'source' => $source]);
        $status->report('classified', sprintf('Using %s task prompt (skipped classification)', $source), [
            'topic' => $classification['topic'],
            'language' => $classification['language'],
            'source' => $classification['source'],
        ]);

        return $classification;
    }

    /**
     * Again skips the sorter: the stored topic and language route the turn;
     * a model picked for the retry decides the topic when its tag says so.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function againForStreamedTurn(Message $message, array $options, TurnStatus $status): array
    {
        $language = $message->getLanguage();
        $classification = $this->againClassification($message, $options, ($language && 'NN' !== $language) ? $language : 'en');
        $status->report('classified', 'Using previously selected model (skipped classification)');

        return $classification;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function againForBufferedTurn(Message $message, array $options, TurnStatus $status): array
    {
        $language = $options['language'] ?? ($message->getLanguage() && 'NN' !== $message->getLanguage() ? $message->getLanguage() : 'en');
        $classification = $this->againClassification($message, $options, $language);
        $status->report('classified', 'Using previously selected model (skipped classification)', [
            'topic' => $classification['topic'],
            'language' => $classification['language'],
            'source' => $classification['source'],
            'model_id' => $classification['model_id'],
        ]);

        return $classification;
    }

    /**
     * WhatsApp images: ChatHandler handles vision, so the topic is `general`.
     * Streamed, the result still runs through {@see finish()}.
     *
     * @return array<string, mixed>
     */
    public function forcedImageForStreamedTurn(Message $message): array
    {
        $this->logger->info('MessageProcessor: Forcing image description mode');

        return self::forcedImageClassification($message->getLanguage() ?: 'en');
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function forcedImageForBufferedTurn(array $options, TurnStatus $status): array
    {
        $classification = self::forcedImageClassification($options['language'] ?? 'en');
        $this->logger->info('MessageProcessor: Forcing image description mode (non-streaming)');
        $status->report('classified', 'Forced image description mode', [
            'topic' => $classification['topic'],
            'language' => $classification['language'],
            'source' => $classification['source'],
        ]);

        return $classification;
    }

    /**
     * A widget turn stays one, whichever path classified it: the handlers
     * answer it without a task plan, memories or a web search.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function withWidgetMode(array $classification, array $options): array
    {
        if (!empty($options['is_widget_mode'])) {
            $classification['is_widget_mode'] = true;
        }

        return $classification;
    }

    /**
     * The knowledge-base folder picked in the composer scopes this turn's
     * retrieval, whichever path classified it.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function withRagScope(array $classification, array $options): array
    {
        if (!empty($options['rag_group_key'])) {
            $classification['rag_group_key'] = $options['rag_group_key'];
        }
        if (!empty($options['rag_limit'])) {
            $classification['rag_limit'] = (int) $options['rag_limit'];
        }
        if (isset($options['rag_min_score'])) {
            $classification['rag_min_score'] = (float) $options['rag_min_score'];
        }

        return $classification;
    }

    /**
     * Streamed: the prompt bundle is resolved once, in the turn's language,
     * and handed to ChatHandler as `resolved_prompt_data`.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [$options, $promptMetadata]
     */
    public function promptForStreamedTurn(Message $message, array $classification, array $options, PerfTimer $perfTimer): array
    {
        $perfTimer->start('prompt');
        $promptData = $this->promptService->getPromptWithMetadata($classification['topic'] ?? 'general', $message->getUserId(), $classification['language'] ?? 'en');
        $perfTimer->stop('prompt');

        if (null !== $promptData) {
            $options['resolved_prompt_data'] = $promptData;
        }

        return [$options, $promptData['metadata'] ?? []];
    }

    /**
     * Buffered: the classifier's prompt metadata when it carries one, else a
     * lookup by topic; handed on as `prompt_metadata`.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} [$classification, $options, $promptMetadata]
     */
    public function promptForBufferedTurn(Message $message, array $classification, array $options): array
    {
        $promptMetadata = is_array($classification['prompt_metadata'] ?? null) ? $classification['prompt_metadata'] : [];
        if ([] === $promptMetadata && !empty($classification['topic'])) {
            $promptData = $this->promptService->getPromptWithMetadata($classification['topic'], $message->getUserId());
            if ($promptData) {
                $promptMetadata = $promptData['metadata'] ?? [];
                $classification['prompt_metadata'] = $promptMetadata;
            }
        }

        if (!empty($promptMetadata)) {
            $options['prompt_metadata'] = $promptMetadata;
        }

        return [$classification, $options, $promptMetadata];
    }

    /**
     * Saved Task runs get their own source: they skip the sorter like widget
     * and API turns, but the executor must still plan them, or a multi-step
     * task silently degrades to a chat answer.
     *
     * @param array<string, mixed> $options
     *
     * @return array{0: bool, 1: string}
     */
    private static function fixedPromptSource(array $options): array
    {
        $isWidget = !empty($options['is_widget_mode']);

        return [$isWidget, $isWidget ? 'widget' : (!empty($options['saved_task']) ? 'saved_task' : 'api')];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function fixedPromptClassification(array $options, string $language, string $source, bool $isWidget): array
    {
        $classification = [
            'topic' => $options['fixed_task_prompt'],
            'language' => $language,
            'source' => $source,
            'intent' => 'chat',
            'is_widget_mode' => $isWidget,
        ];
        // The widget's configured model, so ChatHandler answers with it.
        if (!empty($options['widget_model_id'])) {
            $classification['override_model_id'] = (int) $options['widget_model_id'];
        }

        return $classification;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function againClassification(Message $message, array $options, string $language): array
    {
        $topic = strtolower($message->getTopic() ?: '');
        if ('' === $topic || 'unknown' === $topic) {
            $topic = 'chat';
        }
        if (!empty($options['model_id'])) {
            $modelTag = $this->modelConfigService->getModelTag((int) $options['model_id']);
            if ($modelTag) {
                $topic = self::topicForModelTag($modelTag, $topic);
            }
        }

        $this->logger->info('MessageProcessor: Skipping classification (Again request)', [
            'specified_model_id' => $options['model_id'],
            'topic' => $topic,
            'language' => $language,
        ]);

        return [
            'topic' => $topic,
            'language' => $language,
            'source' => 'again',
            'intent' => self::intentForAgainTopic($topic),
            'model_id' => $options['model_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function forcedImageClassification(string $language): array
    {
        return ['topic' => 'general', 'language' => $language, 'source' => 'forced_image_description', 'intent' => 'chat'];
    }

    /**
     * The verdict in the keys the progress timeline narrates ("an image
     * request", "needs a web search", "several steps"); only keys with a value.
     *
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function summary(array $classification): array
    {
        $summary = [];
        foreach (['intent', 'media_type'] as $key) {
            if (is_string($classification[$key] ?? null) && '' !== $classification[$key]) {
                $summary[$key] = $classification[$key];
            }
        }
        foreach (['web_search', 'multi_step'] as $key) {
            if (is_bool($classification[$key] ?? null)) {
                $summary[$key] = $classification[$key];
            }
        }

        return $summary;
    }

    private static function intentForAgainTopic(string $topic): string
    {
        return match ($topic) {
            'mediamaker', 'text2pic', 'text2vid', 'text2sound' => 'image_generation',
            'pic2text', 'analyze', 'analyzefile' => 'file_analysis',
            'officemaker' => 'document_generation',
            default => 'chat',
        };
    }

    private static function topicForModelTag(string $modelTag, string $fallback): string
    {
        return match (strtolower($modelTag)) {
            'text2pic', 'text2vid', 'text2sound' => 'mediamaker',
            'pic2text', 'analyze', 'vision' => 'general',
            'document', 'officemaker', 'text2doc' => 'officemaker',
            default => $fallback ?: 'chat',
        };
    }
}
