<?php

namespace App\Service\Message\Processing;

use App\Entity\Message;
use App\Service\Mcp\ConfluenceLinkedPageRead;
use App\Service\Mcp\ConfluenceLinkedPageReader;
use App\Service\Mcp\JiraLinkedIssueRead;
use App\Service\Mcp\JiraLinkedIssueReader;
use App\Service\Research\UrlContentService;
use App\Service\Research\WebResearchService;
use Psr\Log\LoggerInterface;

/**
 * Reads the links pasted into a message into classification['url_content'],
 * so the answer comes from the page (ChatHandler, and UrlFetchRunner reuse).
 *
 * Confluence and Jira links are behind a login: they are read through the
 * user's Atlassian connection and kept away from the public reader, so the
 * answer is the page — or an honest connection error — never the sign-in
 * screen. With {@see WebResearchService} (production wiring) every other
 * link is read by default (`URL_READ.ENABLED`): redirects and shortlinks are
 * followed, login walls reported, large pages condensed to the question.
 * Without it, the legacy light fetch runs for the prompt flag
 * `tool_url_screenshot` and for Saved Task reruns.
 */
final readonly class LinkedContentReader
{
    public function __construct(
        private UrlContentService $urlContentService,
        private LoggerInterface $logger,
        private ?WebResearchService $webResearch = null,
        private ?ConfluenceLinkedPageReader $confluencePages = null,
        private ?JiraLinkedIssueReader $jiraIssues = null,
    ) {
    }

    /**
     * @param array<string, mixed> $promptMetadata
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    public function read(Message $message, array $promptMetadata, array $classification, TurnStatus $status): array
    {
        $urls = $this->urlContentService->extractUrls((string) $message->getText());
        if ([] === $urls) {
            return $classification;
        }

        $classification = $this->readThroughConnections($message, $urls, $classification, $status, $promptMetadata);
        $handled = array_merge(
            is_array($classification['confluence_mcp_urls'] ?? null) ? $classification['confluence_mcp_urls'] : [],
            is_array($classification['jira_mcp_urls'] ?? null) ? $classification['jira_mcp_urls'] : [],
        );
        $urls = array_values(array_filter($urls, static fn (string $url): bool => !in_array($url, $handled, true)));
        if ([] === $urls) {
            return $classification;
        }

        $savedTask = 'saved_task' === ($classification['source'] ?? null) || !empty($classification['saved_task_id']);
        $promptOptIn = !empty($promptMetadata['tool_url_screenshot']);
        $autoRead = null !== $this->webResearch && $this->webResearch->isUrlReadEnabled();
        if (!$savedTask && !$promptOptIn && !$autoRead) {
            return $classification;
        }

        if (null === $this->webResearch) {
            $status->report('fetching_urls', sprintf('Fetching content from %d URL(s)...', count($urls)));

            $results = $this->urlContentService->fetchMultiple($urls);
            $successCount = count(array_filter($results, static fn ($r) => $r->success));
            // Always record the outcome, including total failure: generator
            // nodes treat "named URL, zero pages read" as a terminal honest
            // failure instead of inventing content (#2050).
            $classification['url_pages_read'] = (int) ($classification['url_pages_read'] ?? 0) + $successCount;

            if ($successCount > 0) {
                $classification = $this->appendUrlContent($classification, $this->urlContentService->formatForPrompt($results));
                $status->report('urls_fetched', sprintf('Extracted content from %d URL(s)', $successCount));
            }

            return $classification;
        }

        try {
            $read = $this->webResearch->readMentionedUrls($urls, (string) $message->getText(), $message->getUserId(), $status->asCallable());
        } catch (\Throwable $e) {
            $this->logger->warning('MessageProcessor: reading linked pages failed', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);

            // A failed read is still a read outcome: the explicit zero keeps
            // generator guards from mistaking it for "no read ran" (#2050).
            $classification['url_pages_read'] ??= 0;

            return $classification;
        }

        $classification = $this->appendUrlContent($classification, $this->webResearch->formatMentionedUrlsForPrompt($read));
        $classification['url_pages'] = $read->toClientList();
        $classification['url_pages_read'] = (int) ($classification['url_pages_read'] ?? 0) + $read->successCount();
        $classification['url_page_context'] = $read->contextForQuery();

        return $classification;
    }

    /**
     * The same gates as mcp_fetch apply first: the topic (or the pinned
     * assistant) must have `tool_mcp` on, and an `mcp_servers` allow-list
     * limits which connection may be called. No outbound call otherwise.
     *
     * @param list<string>         $urls
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $promptMetadata
     *
     * @return array<string, mixed>
     */
    private function readThroughConnections(Message $message, array $urls, array $classification, TurnStatus $status, array $promptMetadata): array
    {
        if (true !== ($promptMetadata['tool_mcp'] ?? null)) {
            return $classification;
        }

        $allowlist = $this->mcpServerAllowlist($promptMetadata);
        if (null !== $this->confluencePages) {
            $read = $this->confluencePages->read($urls, $message->getUserId(), $allowlist,
                static fn () => $status->report('fetching_urls', 'Reading the Confluence page from your connection...'));
            $classification = $this->applyLinkedRead($classification, $read, $urls, 'confluence');
        }
        if (null !== $this->jiraIssues) {
            $read = $this->jiraIssues->read($urls, $message->getUserId(), $allowlist,
                static fn () => $status->report('fetching_urls', 'Reading the Jira issue from your connection...'));
            $classification = $this->applyLinkedRead($classification, $read, $urls, 'jira');
        }

        return $classification;
    }

    /**
     * @param array<string, mixed> $classification
     * @param list<string>         $urls
     *
     * @return array<string, mixed>
     */
    private function applyLinkedRead(array $classification, ConfluenceLinkedPageRead|JiraLinkedIssueRead $read, array $urls, string $source): array
    {
        if (!$read->attempted()) {
            return $classification;
        }

        $classification = $this->appendUrlContent($classification, $read->prompt());
        $classification[$source.'_mcp_urls'] = $read->handledUrls();
        $classification[$source.'_mcp_handled'] = $read->coversAll($urls);
        $classification['url_pages_read'] = (int) ($classification['url_pages_read'] ?? 0) + $read->successCount();

        return $classification;
    }

    /**
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function appendUrlContent(array $classification, string $text): array
    {
        if ('' === $text) {
            return $classification;
        }

        $existing = is_string($classification['url_content'] ?? null) ? $classification['url_content'] : '';
        $classification['url_content'] = '' !== $existing ? $existing."\n\n".$text : $text;

        return $classification;
    }

    /**
     * Optional `mcp_servers` prompt metadata. A blank topic string means every
     * enabled connection; a list (an assistant pin) is exact, so an empty list
     * allows none.
     *
     * @param array<string, mixed> $promptMetadata
     *
     * @return list<int>|null
     */
    private function mcpServerAllowlist(array $promptMetadata): ?array
    {
        $raw = $promptMetadata['mcp_servers'] ?? null;
        if (is_array($raw)) {
            return array_values(array_map(intval(...), array_filter($raw, static fn ($part): bool => is_numeric($part) && (int) $part > 0)));
        }
        if (!is_string($raw) || '' === trim($raw)) {
            return null;
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (is_numeric($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return [] === $ids ? null : $ids;
    }
}
