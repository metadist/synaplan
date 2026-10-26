<?php

declare(strict_types=1);

namespace App\Service\Message\Processing;

use App\Entity\Message;
use App\Plug\WebSearch\WebSearchGateway;
use App\Repository\SearchResultRepository;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\AttachmentSearchContextResolver;
use App\Service\Message\ReadPagesPolicy;
use App\Service\Message\SearchQueryGenerator;
use App\Service\Message\WebSearchTopicPolicy;
use App\Service\Research\WebResearchService;
use App\Service\Runtime\RuntimeProfile;
use Psr\Log\LoggerInterface;

/**
 * Pasted links first, then the web search, for one turn.
 *
 * The decision mirrors {@see WebSearchTopicPolicy::shouldSearch()}: a prompt
 * opt-out (`tool_internet=false`) never searches; a request for this message
 * (chat toggle, `/search`, "search the web …") always does; asset and document
 * topics never do; otherwise the classifier's vote counts, vetoed for trivial
 * greetings. Links are read before searching, so a bare link is answered from
 * the page and a question citing a page searches for the page's topic, not the
 * URL. A question about an attached file searches for the file's content —
 * never for the literal "what is that".
 */
final readonly class TurnWebSearch
{
    public function __construct(
        private WebSearchGateway $webSearch,
        private SearchQueryGenerator $searchQueryGenerator,
        private AttachmentSearchContextResolver $attachmentContextResolver,
        private LinkedContentReader $linkedContent,
        private LoggerInterface $logger,
        private ?SearchResultRepository $searchResultRepository = null,
        private ?WebResearchService $webResearch = null,
    ) {
    }

    /**
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $promptMetadata
     * @param array<string, mixed>                                     $options
     * @param array<int, Message|array{role: string, content: string}> $history
     *
     * @return array{classification: array<string, mixed>, searchResults: array<string, mixed>|null}
     */
    public function run(Message $message, array $classification, array $promptMetadata, array $options, array $history, TurnStatus $status, ?PerfTimer $perfTimer = null): array
    {
        $topic = $classification['topic'] ?? 'general';
        $promptMetadata = $this->applyRuntimeToolFlags($promptMetadata, $classification['runtime_profile'] ?? $options['runtime_profile'] ?? null);
        $promptToolInternet = PromptToolFlag::read($promptMetadata, 'tool_internet');
        $classifierVote = $classification['web_search'] ?? null;
        $messageText = $message->getText();
        $userRequestedSearch = $this->userRequestedSearch($options, $classification)
            || WebSearchTopicPolicy::isExplicitSearchRequest($messageText);
        $widgetTurn = !empty($options['is_widget_mode']) || !empty($classification['is_widget_mode']);
        $shouldSearch = !$widgetTurn && WebSearchTopicPolicy::shouldSearch($topic, $userRequestedSearch, $promptToolInternet, $classifierVote, $messageText);
        $triggerReason = $widgetTurn
            ? 'widget_turn'
            : $this->triggerReasonFor($topic, $userRequestedSearch, $promptToolInternet, $classifierVote, $messageText, $shouldSearch);
        $needsAttachmentContext = $shouldSearch && $message->hasFiles() && WebSearchTopicPolicy::refersToAttachment($messageText);

        $perfTimer?->start('url_read');
        $classification = $this->linkedContent->read($message, $promptMetadata, $classification, $status);
        $perfTimer?->stop('url_read');
        [$shouldSearch, $triggerReason] = $this->suppressSearchAfterConnectionRead($shouldSearch, $userRequestedSearch, $classification, $triggerReason);
        if ($shouldSearch && $this->linkOnlyMessageWasRead($classification, $messageText, $userRequestedSearch)) {
            $shouldSearch = false;
            $triggerReason = 'link_only_message_answered_from_page';
        }

        // One decision line, so "search didn't trigger" reports need no
        // correlation across services.
        $braveEnabled = $this->webSearch->isEnabled($message->getUserId());
        $this->logger->info('MessageProcessor: Web search decision', [
            'message_id' => $message->getId(),
            'should_search' => $shouldSearch,
            'trigger_reason' => $triggerReason,
            'user_requested_search' => $userRequestedSearch,
            'prompt_tool_internet' => $promptToolInternet,
            'classifier_web_search_hint' => $classification['web_search'] ?? null,
            'classification_source' => $classification['source'] ?? null,
            'classification_topic' => $topic,
            'needs_attachment_context' => $needsAttachmentContext,
            'linked_pages_read' => $classification['url_pages_read'] ?? 0,
            'read_pages_vote' => $classification['read_pages'] ?? null,
            'brave_enabled' => $braveEnabled,
        ]);

        if ($shouldSearch && !$braveEnabled) {
            $this->logger->warning('MessageProcessor: Web search requested but Brave Search is disabled', [
                'message_id' => $message->getId(),
                'trigger_reason' => $triggerReason,
            ]);
        }

        $attachmentContext = null;
        if ($needsAttachmentContext && $braveEnabled) {
            $perfTimer?->start('search_attachment_context');
            $attachmentContext = $this->attachmentContextResolver->resolve($message, $message->getUserId());
            $perfTimer?->stop('search_attachment_context');

            if (null === $attachmentContext && !$userRequestedSearch) {
                // The question is about the attachment but its content could
                // not be resolved: a vote-only search would query the literal
                // deictic words. The answer model still analyzes the file; an
                // explicit request keeps searching with the raw text.
                $shouldSearch = false;
                $this->logger->info('MessageProcessor: Skipping vote-triggered web search — attachment referent unresolvable', [
                    'message_id' => $message->getId(),
                ]);
            }
        }

        $searchResults = null;
        if ($shouldSearch && $braveEnabled) {
            $searchResults = $this->search($message, $classification, $options, $history, $status, $perfTimer, $attachmentContext, $userRequestedSearch);
        }

        return ['classification' => $classification, 'searchResults' => $searchResults];
    }

    /**
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     * @param array<int, Message|array{role: string, content: string}> $history
     *
     * @return array<string, mixed>|null
     */
    private function search(Message $message, array $classification, array $options, array $history, TurnStatus $status, ?PerfTimer $perfTimer, ?string $attachmentContext, bool $userRequestedSearch): ?array
    {
        $status->report('searching', 'Searching the web...');
        $incognito = !empty($options['incognito']);
        $searchResults = null;

        try {
            // A linked page just read is the best hint for what a thin message wants.
            $perfTimer?->start('search_query');
            $searchQuery = $this->searchQueryGenerator->generate(
                $message->getText(),
                $message->getUserId(),
                $attachmentContext ?? $this->linkedPageContext($classification),
                $this->threadContextForSearch($history, $message),
            );
            $perfTimer?->stop('search_query');

            $language = $this->resolveSearchLanguage($classification, $message);
            $this->logger->info('🔍 Performing web search', [
                'original_question' => $incognito ? '[incognito]' : $message->getText(),
                'optimized_query' => $incognito ? '[incognito]' : $searchQuery,
                'language' => $language,
                'country' => $language,
                'message_id' => $message->getId(),
            ]);

            $perfTimer?->start('search_brave');
            $searchResults = $this->webSearch->search($searchQuery, ['country' => $language, 'search_lang' => $language], $message->getUserId());
            $perfTimer?->stop('search_brave');

            if (!$searchResults || empty($searchResults['results'])) {
                $this->logger->warning('No search results found or repository not available', [
                    'query' => $incognito ? '[incognito]' : $searchQuery,
                    'provider' => $this->webSearchMetaString($searchResults, 'provider'),
                    'fellBackFrom' => $this->webSearchMetaString($searchResults, 'fellBackFrom'),
                    'has_repository' => null !== $this->searchResultRepository,
                ]);

                return null;
            }

            // Incognito: the transient message has no id — results stay in
            // memory (streamed and in the AI context) and are never persisted.
            if ($this->searchResultRepository && null !== $message->getId()) {
                $this->searchResultRepository->saveSearchResults($message, $searchResults, $searchQuery);
            }

            // The sources reach the client the moment the search returns, so
            // the sources box renders while the answer is still generating.
            $status->report('search_complete', sprintf('Found %d web results', count($searchResults['results'])), [
                'results_count' => count($searchResults['results']),
                'query' => $searchQuery,
                'results' => $this->formatSearchResultsForClient($searchResults['results']),
            ]);

            // Snippets alone made the model hedge; the page bodies, condensed
            // to the question, are the evidence it needs.
            $perfTimer?->start('search_read_pages');
            $searchResults = $this->deepenSearchResults($searchResults, $message, $classification, $userRequestedSearch, $status);
            $perfTimer?->stop('search_read_pages');

            return $searchResults;
        } catch (\Exception $e) {
            $this->logger->error('Web search failed', [
                'error' => $e->getMessage(),
                'message_id' => $message->getId(),
            ]);
            $status->report('search_failed', 'Web search failed, continuing without results');

            // Results that already arrived before the failure still count.
            return $searchResults;
        }
    }

    /**
     * An assistant pin replaces the topic's tool flags for this turn.
     *
     * @param array<string, mixed> $promptMetadata
     *
     * @return array<string, mixed>
     */
    private function applyRuntimeToolFlags(array $promptMetadata, mixed $profile): array
    {
        if (!$profile instanceof RuntimeProfile) {
            return $promptMetadata;
        }
        foreach (['tool_internet', 'tool_mcp'] as $flag) {
            if (array_key_exists($flag, $profile->toolFlags)) {
                $promptMetadata[$flag] = (bool) $profile->toolFlags[$flag];
            }
        }
        if (array_key_exists('mcp_servers', $profile->toolFlags)) {
            $promptMetadata['mcp_servers'] = $profile->toolFlags['mcp_servers'];
        }

        return $promptMetadata;
    }

    /**
     * A page already read through the user's Atlassian connection is the
     * answer; a public search would only find the login screen. An explicit
     * search request still runs.
     *
     * @param array<string, mixed> $classification
     *
     * @return array{0: bool, 1: string}
     */
    private function suppressSearchAfterConnectionRead(bool $shouldSearch, bool $userRequestedSearch, array $classification, string $triggerReason): array
    {
        if ($shouldSearch && !$userRequestedSearch && true === ($classification['confluence_mcp_handled'] ?? false)) {
            return [false, 'confluence_page_read_via_connection'];
        }
        if ($shouldSearch && !$userRequestedSearch && true === ($classification['jira_mcp_handled'] ?? false)) {
            return [false, 'jira_issue_read_via_connection'];
        }
        if ($shouldSearch && !$userRequestedSearch && true === ($classification['notion_mcp_handled'] ?? false)) {
            return [false, 'notion_page_read_via_connection'];
        }

        return [$shouldSearch, $triggerReason];
    }

    /**
     * Read the top result pages and attach their condensed content. The
     * sorter's BREADPAGES vote decides how many (0 / 2 / 3); any failure
     * leaves the snippet-only results.
     *
     * @param array<string, mixed> $searchResults
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function deepenSearchResults(array $searchResults, Message $message, array $classification, bool $userRequestedSearch, TurnStatus $status): array
    {
        if (null === $this->webResearch || !$this->webResearch->isDeepSearchEnabled()) {
            return $searchResults;
        }

        $vote = $classification['read_pages'] ?? null;
        $maxPages = ReadPagesPolicy::pagesToRead(is_int($vote) ? $vote : null, ($classification['url_pages_read'] ?? 0) >= 1, $userRequestedSearch);
        if ($maxPages <= 0) {
            $this->logger->info('MessageProcessor: skipping page dumps — router voted snippets only', [
                'message_id' => $message->getId(),
                'read_pages_vote' => $vote,
            ]);

            return $searchResults;
        }

        try {
            $deepened = $this->webResearch->deepen($searchResults, (string) $message->getText(), $message->getUserId(), $status->asCallable(), $maxPages);
        } catch (\Throwable $e) {
            $this->logger->warning('MessageProcessor: reading search result pages failed', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);

            return $searchResults;
        }

        $pagesRead = (int) ($deepened['pages_read'] ?? 0);
        $status->report('pages_read', sprintf('Read %d web page%s', $pagesRead, 1 === $pagesRead ? '' : 's'), [
            'pages_read' => $pagesRead,
            'pages_attempted' => (int) ($deepened['pages_attempted'] ?? 0),
            'results' => $this->formatSearchResultsForClient($deepened['results'] ?? []),
        ]);

        return $deepened;
    }

    /**
     * A message that is nothing but link(s) whose page was just read needs no
     * vote-triggered search; explicit requests still search.
     *
     * @param array<string, mixed> $classification
     */
    private function linkOnlyMessageWasRead(array $classification, ?string $messageText, bool $userRequestedSearch): bool
    {
        if ($userRequestedSearch || ($classification['url_pages_read'] ?? 0) < 1) {
            return false;
        }

        $withoutUrls = preg_replace('#https?://\S+#i', '', (string) $messageText) ?? '';
        $remaining = preg_replace('/[^\p{L}\p{N}]+/u', '', $withoutUrls) ?? '';

        return mb_strlen($remaining) < 12;
    }

    /**
     * @param array<string, mixed> $classification
     */
    private function linkedPageContext(array $classification): ?string
    {
        $context = $classification['url_page_context'] ?? null;

        return is_string($context) && '' !== trim($context) ? $context : null;
    }

    /**
     * The previous turns, so a follow-up that does not repeat the subject
     * ("search for the text yourself") is searched as that subject. The
     * message being answered is never part of its own context.
     *
     * @param array<int, Message|array{role?: string, content?: string, text?: string}> $history oldest first
     */
    private function threadContextForSearch(array $history, Message $current): ?string
    {
        if ([] === $history) {
            return null;
        }

        $previous = array_values(array_filter($history, static function (Message|array $entry) use ($current): bool {
            if (!$entry instanceof Message) {
                return true;
            }
            if (null !== $current->getId() && $entry->getId() === $current->getId()) {
                return false;
            }

            return !('IN' === $entry->getDirection() && $entry->getText() === $current->getText());
        }));

        $lines = [];
        foreach (array_slice($previous, -4) as $entry) {
            if ($entry instanceof Message) {
                $role = 'IN' === $entry->getDirection() ? 'User' : 'Assistant';
                $text = $entry->getText();
            } else {
                $role = 'assistant' === ($entry['role'] ?? '') ? 'Assistant' : 'User';
                $text = $entry['content'] ?? $entry['text'] ?? '';
            }

            $text = preg_replace('/<think>.*?<\/think>/s', '', $text) ?? $text;
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if ('' === $text) {
                continue;
            }
            if (mb_strlen($text) > 500) {
                $text = mb_substr($text, 0, 500).'…';
            }
            $lines[] = $role.': '.$text;
        }

        return [] === $lines ? null : implode("\n", $lines);
    }

    /**
     * Why the decision went the way it did, in the precedence of
     * {@see WebSearchTopicPolicy::shouldSearch()}.
     */
    private function triggerReasonFor(?string $topic, bool $userRequestedSearch, ?bool $promptToolInternet, ?bool $classifierVote, ?string $messageText, bool $shouldSearch): string
    {
        if ($shouldSearch) {
            return $userRequestedSearch ? 'user_requested_search' : 'classifier_vote_search';
        }
        if (false === $promptToolInternet) {
            return 'disabled_by_prompt_tool_internet';
        }
        if (WebSearchTopicPolicy::isNonWebSearchTopic($topic)) {
            return 'non_web_search_topic';
        }
        if (true === $classifierVote && WebSearchTopicPolicy::isTrivialConversational($messageText)) {
            return 'suppressed_trivial_conversation';
        }

        return 'classifier_vote_no_search';
    }

    /**
     * An explicit request for this message: `web_search` from the stream (chat
     * toggle, `/search`), `force_web_search` from the legacy path or the shared
     * slash parser on a non-web channel.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $classification
     */
    private function userRequestedSearch(array $options, array $classification): bool
    {
        return (bool) ($options['web_search'] ?? false)
            || (bool) ($options['force_web_search'] ?? false)
            || (bool) ($classification['force_web_search'] ?? false);
    }

    /**
     * Brave search_lang / country: classifier BLANG, then the message language
     * (seeded from the UI locale), then `en`. `auto` is not a Brave code.
     *
     * @param array<string, mixed> $classification
     */
    private function resolveSearchLanguage(array $classification, Message $message): string
    {
        foreach ([$classification['language'] ?? null, $message->getLanguage()] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $normalized = strtolower(trim($candidate));
            if ('' !== $normalized && 'auto' !== $normalized && 'nn' !== $normalized) {
                return $normalized;
            }
        }

        return 'en';
    }

    /**
     * Raw results in the client source-card shape, identical to the final
     * `complete` payload.
     *
     * @return list<array<string, mixed>>
     */
    private function formatSearchResultsForClient(mixed $results): array
    {
        if (!is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $profile = $result['profile'] ?? null;
            $out[] = [
                'title' => is_string($result['title'] ?? null) ? $result['title'] : '',
                'url' => is_string($result['url'] ?? null) ? $result['url'] : '',
                'description' => is_string($result['description'] ?? null) ? $result['description'] : '',
                'published' => $result['age'] ?? null,
                'source' => is_array($profile) ? ($profile['name'] ?? null) : null,
                'thumbnail' => $result['thumbnail'] ?? null,
                'fetched' => (bool) ($result['fetched'] ?? false),
                'final_url' => is_string($result['final_url'] ?? null) ? $result['final_url'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $searchResults
     */
    private function webSearchMetaString(?array $searchResults, string $key): ?string
    {
        $value = $searchResults['query_metadata'][$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }
}
