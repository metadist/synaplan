<?php

namespace App\Service\Message\Processing;

use App\Entity\Agent;
use App\Entity\Message;
use App\Service\Agent\AgentConfig;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\MessageClassifier;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use App\Service\Runtime\RuntimeProfile;
use Psr\Log\LoggerInterface;

/**
 * What a turn is about and which prompt answers it.
 *
 * Fixed-prompt turns (widget, API, Saved Task), Again and the forced image
 * description skip the AI sorter; everything else is classified. Both
 * transports decide alike: one language rule, one method per kind of turn,
 * one prompt lookup. Per transport only the classified status differs: a
 * buffered turn's carries the verdict.
 */
final readonly class TurnClassificationResolver
{
    private const AGAIN_STATUS = 'Using previously selected model (skipped classification)';

    public function __construct(
        private MessageClassifier $classifier,
        private ModelConfigService $modelConfigService,
        private AgentConfig $agentConfig,
        private PromptService $promptService,
        private TopicIntentRule $topicIntents,
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
     * Streamed, the classified status names the source only.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function fixedPromptForStreamedTurn(Message $message, array $options, TurnStatus $status): array
    {
        $classification = $this->fixedPrompt($message, $options);
        $status->report('classified', sprintf('%s: Using fixed prompt', ucfirst($classification['source'])));

        return $classification;
    }

    /**
     * Buffered, the classified status carries the verdict.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function fixedPromptForBufferedTurn(Message $message, array $options, TurnStatus $status): array
    {
        $classification = $this->fixedPrompt($message, $options);
        $status->report('classified', sprintf('Using %s task prompt (skipped classification)', $classification['source']), self::verdict($classification));

        return $classification;
    }

    /**
     * Streamed, the classified status names no verdict.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function againForStreamedTurn(Message $message, array $options, TurnStatus $status): array
    {
        $classification = $this->again($message, $options);
        $status->report('classified', self::AGAIN_STATUS);

        return $classification;
    }

    /**
     * Buffered, the classified status carries the verdict.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function againForBufferedTurn(Message $message, array $options, TurnStatus $status): array
    {
        $classification = $this->again($message, $options);
        $status->report('classified', self::AGAIN_STATUS, self::verdict($classification) + ['model_id' => $classification['model_id']]);

        return $classification;
    }

    /**
     * WhatsApp images: ChatHandler handles vision, so the topic is `general`.
     * A streamed turn reports it through {@see finish()}.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function forcedImage(Message $message, array $options): array
    {
        $this->logger->info('MessageProcessor: Forcing image description mode');

        return ['topic' => 'general', 'language' => self::language($message, $options, 'en'), 'source' => 'forced_image_description', 'intent' => 'chat'];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function forcedImageForBufferedTurn(Message $message, array $options, TurnStatus $status): array
    {
        $classification = $this->forcedImage($message, $options);
        $status->report('classified', 'Forced image description mode', self::verdict($classification));

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
     * The prompt that answers the turn, looked up once in the turn's language
     * unless the classifier already carries its metadata. The metadata goes
     * on the classification (media generation and file analysis read the
     * prompt's `aiModel` there), the whole bundle into `resolved_prompt_data`,
     * which ChatHandler reuses.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} [$classification, $options, $promptMetadata]
     */
    public function prompt(Message $message, array $classification, array $options, ?PerfTimer $perfTimer = null): array
    {
        $carried = $classification['prompt_metadata'] ?? null;
        if (is_array($carried) && [] !== $carried) {
            return [$classification, $options, $carried];
        }

        $perfTimer?->start('prompt');
        $promptData = $this->promptService->getPromptWithMetadata($classification['topic'] ?? 'general', $message->getUserId(), $classification['language'] ?? 'en');
        $perfTimer?->stop('prompt');
        if (null === $promptData) {
            return [$classification, $options, []];
        }

        $options['resolved_prompt_data'] = $promptData;
        $classification['prompt_metadata'] = $promptData['metadata'] ?? [];

        return [$classification, $options, $classification['prompt_metadata']];
    }

    /**
     * A fixed prompt answers without the sorter. Without a caller or message
     * language it answers `auto`, following whatever the user writes. Saved
     * Task runs get a source of their own: the executor must still plan them,
     * or a multi-step task silently degrades to a chat answer.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function fixedPrompt(Message $message, array $options): array
    {
        $isWidget = !empty($options['is_widget_mode']);
        $classification = [
            'topic' => $options['fixed_task_prompt'],
            'language' => self::language($message, $options, 'auto'),
            'source' => $isWidget ? 'widget' : (!empty($options['saved_task']) ? 'saved_task' : 'api'),
            'intent' => 'chat',
            'is_widget_mode' => $isWidget,
        ];
        // The widget's configured model, so ChatHandler answers with it.
        if (!empty($options['widget_model_id'])) {
            $classification['override_model_id'] = (int) $options['widget_model_id'];
        }

        $this->logger->info('MessageProcessor: Using fixed prompt (skipped AI classification)', [
            'language' => $classification['language'],
            'fixed_topic' => $classification['topic'],
            'source' => $classification['source'],
            'widget_model_id' => $options['widget_model_id'] ?? null,
        ]);

        return $classification;
    }

    /**
     * Again skips the sorter: the picked model's tag and the stored topic
     * route the retry ({@see TopicIntentRule::forModel()}).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function again(Message $message, array $options): array
    {
        $language = self::language($message, $options, 'en');
        $modelTag = !empty($options['model_id']) ? $this->modelConfigService->getModelTag((int) $options['model_id']) : null;
        $routed = $this->topicIntents->forModel($modelTag, $message->getTopic());

        $this->logger->info('MessageProcessor: Skipping classification (Again request)', [
            'specified_model_id' => $options['model_id'],
            'topic' => $routed->topic,
            'language' => $language,
        ]);

        return [
            'topic' => $routed->topic,
            'language' => $language,
            'source' => 'again',
            'intent' => $routed->intent,
            'model_id' => $routed->usesPickedModel ? $options['model_id'] : null,
        ];
    }

    /**
     * The answer language when the sorter does not run: an explicit caller
     * language wins, then the message's own, then `$unknown`.
     *
     * @param array<string, mixed> $options
     */
    private static function language(Message $message, array $options, string $unknown): string
    {
        $caller = $options['language'] ?? null;
        if (is_string($caller) && '' !== $caller) {
            return $caller;
        }
        $own = $message->getLanguage();

        return '' !== $own && Message::UNKNOWN_LANGUAGE !== $own ? $own : $unknown;
    }

    /**
     * The verdict a buffered turn's classified status carries.
     *
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private static function verdict(array $classification): array
    {
        return ['topic' => $classification['topic'], 'language' => $classification['language'], 'source' => $classification['source']];
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
}
