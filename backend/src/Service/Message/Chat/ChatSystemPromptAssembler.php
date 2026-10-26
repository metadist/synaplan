<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

use App\Service\Document\ChatDocumentOutput;
use App\Service\Prompt\ChatPromptBlocks;
use App\Service\Prompt\ChatSystemPromptBuilder;
use App\Service\Prompt\LanguageDirectiveBuilder;
use App\Service\Runtime\RuntimeProfile;
use Psr\Log\LoggerInterface;

/**
 * Puts the system prompt of a chat turn together, section by section.
 *
 * Every channel opens the same way: the assistant or topic prompt with its
 * platform hints, then the knowledge base, memories, feedback, digests and
 * the rolling conversation summary. Every channel closes with the person's
 * situation: language, country, local time and, per topic, the images a
 * document may embed or the picture being edited.
 *
 * In between, the channels differ on purpose. Only the web chat carries
 * live widget API data and the continuation and voice-mode rules; only a
 * question from the live voice carries the spoken-answer rule. Web results
 * and a quoted excerpt join the system role last; for a model without a
 * system role they stay in the user turn.
 */
final readonly class ChatSystemPromptAssembler
{
    private const DEFAULT_PROMPT = 'You are the Synaplan.com AI assistant. Please answer in the language of the user.';

    private const LIVE_VOICE_REPLY_RULE = "\n\nThis answer is spoken aloud in a live voice conversation. "
        .'Answer in at most three short spoken sentences. No markdown, lists, tables, links, or emoji. '
        .'Reply in the language of the question.';

    private const CONTINUATION_RULE = "\n\n**CONTINUATION: Your previous response was cut off by the token limit. Continue EXACTLY where you left off. Do NOT repeat, summarize, or re-introduce anything you already said. Start immediately from the point where the text was cut off.**";

    private const VOICE_MODE_RULE = "\n\n**VOICE MODE: Your response will be spoken aloud as audio. Keep your answer concise and conversational — maximum 4-5 sentences. Avoid markdown formatting, code blocks, bullet lists, and tables. Write in natural, flowing prose suitable for speech.**";

    public function __construct(
        private ChatSystemPromptBuilder $sections,
        private ChatDocumentOutput $documents,
        private ChatMessageAssembler $messages,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Email, webhook, MCP and the other channels without a token stream.
     *
     * @param array<string, mixed> $options the turn options
     */
    public function forBufferedTurn(ChatTurn $turn, ChatModelChoice $model, array $options, ?callable $progressCallback): ChatSystemPrompt
    {
        ['prompt' => $prompt, 'docs' => $docs] = $this->opening($turn, $model, $options, $progressCallback);

        $prompt = $this->sections->appendPluginContext($prompt, $turn->message, $turn->classification, [
            'channel' => $turn->classification['source'] ?? null,
        ]);

        $urlContent = $turn->classification['url_content'] ?? null;
        if (is_string($urlContent) && '' !== $urlContent) {
            $prompt .= "\n\n".$urlContent;
            $this->logger->info('ChatHandler: URL content appended to system prompt', [
                'url_content_length' => strlen($urlContent),
            ]);
        }

        $prompt .= $this->sections->conversationFileContext($turn->message, $turn->thread);
        $prompt .= $this->sections->connectedFolderReadHonesty($turn->message, $turn->thread);
        $languageDirective = self::languageDirective($turn->language());
        $prompt .= $languageDirective;
        $prompt .= $this->situation($turn, $options);

        if ($turn->isLiveVoice()) {
            $prompt .= self::LIVE_VOICE_REPLY_RULE;
        }

        [$prompt, $fallback] = $this->systemRole($prompt, $model);

        $searchResults = $turn->knowledge->webResults;
        if (null !== $prompt && is_array($searchResults) && !empty($searchResults['results'])) {
            $prompt .= $this->webSearch($searchResults, $languageDirective, $turn->language());
            $searchResults = null;
        }

        if (null !== $prompt && !empty($options['quoted_text'])) {
            $prompt .= ChatPromptBlocks::quotedReference($options);
            $this->logger->info('ChatHandler: Quoted reference appended to system prompt (non-streaming)', [
                'quoted_message_id' => $options['quoted_message_id'] ?? null,
            ]);
            unset($options['quoted_text'], $options['quoted_message_id']);
        }

        return new ChatSystemPrompt($prompt, $fallback, $docs, $options, $searchResults);
    }

    /**
     * The streamed web chat and the chat widget.
     *
     * @param array<string, mixed> $options the turn options
     */
    public function forStreamedTurn(ChatTurn $turn, ChatModelChoice $model, array $options, ?callable $progressCallback): ChatSystemPrompt
    {
        ['prompt' => $prompt, 'docs' => $docs] = $this->opening($turn, $model, $options, $progressCallback);

        // Live API data from widget flow api-type responses.
        $apiContext = $options['api_context'] ?? '';
        if (is_string($apiContext) && '' !== $apiContext) {
            $prompt .= "\n\n".$apiContext;
            $this->logger->info('ChatHandler: API context appended to system prompt', [
                'api_context_length' => \strlen($apiContext),
            ]);
        }

        $prompt = $this->sections->appendPluginContext($prompt, $turn->message, $turn->classification, $options);

        // Linked pages the processor read for this turn: without them a
        // pasted link is fetched and then silently dropped before the model
        // answers.
        $urlContent = $turn->classification['url_content'] ?? null;
        if (is_string($urlContent) && '' !== $urlContent) {
            $prompt .= "\n\n".$urlContent;
            $this->logger->info('ChatHandler: URL content appended to streaming system prompt', [
                'url_content_length' => strlen($urlContent),
            ]);
        }

        $prompt .= $this->sections->conversationFileContext($turn->message, $turn->thread);
        $prompt .= $this->sections->connectedFolderReadHonesty($turn->message, $turn->thread);
        $languageDirective = self::languageDirective($turn->language());
        $prompt .= $languageDirective;

        if (!empty($options['is_continuation'])) {
            $prompt .= self::CONTINUATION_RULE;
        }

        // Spoken answers stay brief.
        if (!empty($options['voice_reply'])) {
            $prompt .= self::VOICE_MODE_RULE;
        }

        $prompt .= $this->situation($turn, $options);

        [$prompt, $fallback] = $this->systemRole($prompt, $model);

        if (null !== $prompt && !empty($options['quoted_text'])) {
            $prompt .= ChatPromptBlocks::quotedReference($options);
            $this->logger->info('ChatHandler: Quoted reference appended to system prompt', [
                'quoted_message_id' => $options['quoted_message_id'] ?? null,
            ]);
            unset($options['quoted_text'], $options['quoted_message_id']);
        }

        if (null !== $prompt && isset($options['search_results']) && !empty($options['search_results']['results'])) {
            $prompt .= $this->webSearch($options['search_results'], $languageDirective, $turn->language());
            unset($options['search_results']);
        }

        return new ChatSystemPrompt($prompt, $fallback, $docs, $options);
    }

    /**
     * The sections every channel starts with, in this order: the assistant
     * or topic prompt, platform hints, knowledge base, memories, feedback,
     * digests and the conversation summary.
     *
     * The summary condenses the older turns of a long thread, so the topic
     * and the person's position survive while the verbatim thread stays
     * inside the memory window. It sits in the system role so a model
     * without one still gets it in the first user turn.
     *
     * @param array<string, mixed> $options
     *
     * @return array{prompt: string, docs: list<array{slug: string, title: string, url: string}>}
     */
    private function opening(ChatTurn $turn, ChatModelChoice $model, array $options, ?callable $progressCallback): array
    {
        $decorated = $this->sections->decorateSelfAware($this->basePrompt($turn), $turn->topic(), $turn->message, $turn->classification, $options, $progressCallback, $model->modelId);
        $prompt = $this->documents->withPdfExportHint($decorated['prompt'], $turn->topic());
        $prompt = $this->documents->withToolsHint($prompt, $turn->topic(), $model->modelId, $turn->message);

        if (!empty($turn->knowledge->context)) {
            $prompt .= $turn->knowledge->context;
            if (!empty($turn->classification['slash_docs'])) {
                $prompt .= ChatPromptBlocks::docsCitationInstruction();
            }
            $this->logger->info('ChatHandler: RAG context appended to system prompt', [
                'topic' => $turn->topic(),
                'rag_context_length' => strlen($turn->knowledge->context),
            ]);
        }

        $memoriesContext = $turn->personal->memories['context'] ?? '';
        if (!empty($memoriesContext)) {
            $prompt .= $memoriesContext;
            $this->logger->info('ChatHandler: Memories context appended to system prompt', [
                'memories_count' => count($turn->personal->loadedMemories()),
                'memories_context_length' => strlen($memoriesContext),
            ]);
        }

        $feedbackContext = $turn->personal->feedback['context'] ?? '';
        if (!empty($feedbackContext)) {
            $prompt .= $feedbackContext;
            $this->logger->info('ChatHandler: Feedback context appended to system prompt', [
                'feedback_context_length' => strlen($feedbackContext),
            ]);
        }

        // Deep-memory digests: references to key messages from older conversations.
        $digestContext = $turn->personal->digests['context'] ?? '';
        if (!empty($digestContext)) {
            $prompt .= $digestContext;
            $this->logger->info('ChatHandler: Digest context appended to system prompt', [
                'digest_count' => count($turn->personal->digests['digests'] ?? []),
                'digest_context_length' => strlen($digestContext),
            ]);
        }

        $conversationSummary = $options['conversation_summary'] ?? '';
        if (is_string($conversationSummary) && '' !== trim($conversationSummary)) {
            $prompt .= ChatPromptBlocks::conversationSummary($conversationSummary);
            $this->logger->info('ChatHandler: Conversation summary appended to system prompt', [
                'summary_length' => \strlen($conversationSummary),
            ]);
        }

        return ['prompt' => $prompt, 'docs' => $decorated['docs']];
    }

    /**
     * An assistant's own system prompt wins over the topic prompt.
     */
    private function basePrompt(ChatTurn $turn): string
    {
        $profile = $turn->profile;
        if ($profile instanceof RuntimeProfile && null !== $profile->systemPrompt && '' !== $profile->systemPrompt) {
            $this->logger->info('ChatHandler: Using runtime profile system prompt', [
                'topic' => $turn->topic(),
                'prompt_length' => strlen($profile->systemPrompt),
            ]);

            return $profile->systemPrompt;
        }

        if ($turn->promptData && isset($turn->promptData['prompt'])) {
            $prompt = $turn->promptData['prompt']->getPrompt();
            $this->logger->info('ChatHandler: Using custom prompt content', [
                'topic' => $turn->topic(),
                'prompt_length' => strlen($prompt),
            ]);

            return $prompt;
        }

        return self::DEFAULT_PROMPT;
    }

    /**
     * Country-only location (Cloudflare CF-IPCountry), the local time
     * (profile timezone, then an unambiguous country, then the server), the
     * images a document may embed (officemaker) and the picture this turn
     * edits (mediamaker).
     *
     * @param array<string, mixed> $options
     */
    private function situation(ChatTurn $turn, array $options): string
    {
        return ChatPromptBlocks::locationContext($options)
            .$this->sections->timeContext($turn->personal->user, $options)
            .$this->sections->documentImageContext($turn->message, $turn->thread, $turn->topic(), $options)
            .ChatPromptBlocks::mediaEditContext($turn->topic(), $options);
    }

    /**
     * A model that rejects the `system` role gets the prompt in front of its
     * first user turn instead. Dropping it would discard the whole topic
     * prompt (that broke officemaker on GPT-5.5 Pro).
     *
     * @return array{0: string|null, 1: string|null} the system prompt, or null and the user-turn fallback
     */
    private function systemRole(string $prompt, ChatModelChoice $model): array
    {
        if (null !== $model->model && !$this->messages->supportsSystemMessages($model->model->getJson())) {
            return [null, $prompt];
        }

        return [$prompt, null];
    }

    /**
     * Web results belong to the system role: in the user turn the model
     * attributes them to the person and the trust boundary blurs (prompt
     * injection, #1067). The language directive follows again, because the
     * English results block would otherwise steer the answer into English.
     *
     * @param array<string, mixed> $searchResults
     */
    private function webSearch(array $searchResults, string $languageDirective, string $language): string
    {
        $this->logger->info('ChatHandler: Web search context appended to system prompt', [
            'results_count' => count($searchResults['results']),
            'query' => $searchResults['query'] ?? '',
            'language' => $language,
        ]);

        return ChatPromptBlocks::webSearchResults($searchResults).$languageDirective;
    }

    /**
     * The detected language, spelled out: the topic prompt only says "answer
     * in the language of the user", and a thread that mixes languages drifts
     * without it. The builder's anti-echo clause keeps smaller models from
     * repeating the directive ("[Please reply in German]").
     */
    private static function languageDirective(string $language): string
    {
        return 'auto' === $language
            ? LanguageDirectiveBuilder::buildAutoDirective()
            : LanguageDirectiveBuilder::buildForLanguage($language);
    }
}
