<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Entity\Connection;
use App\Service\Microsoft\GraphClient;
use Psr\Log\LoggerInterface;

/**
 * Read-only live search over a connected Microsoft 365 mailbox — the second
 * backend of the `email_search` capability (Phase M step M3a/M3b), same
 * stateless contract as {@see ImapMailboxSearcher}: mail content is never
 * persisted, it exists only in the turn context.
 *
 * Graph search returns ~255-char previews only. A summarize node needs real
 * content, so the FULL body is fetched for the TOP (newest) hit — exactly
 * one extra request, never in bulk — and capped at the same snippet budget
 * as the IMAP searcher.
 */
final readonly class GraphMailboxSearcher
{
    /** Per-message snippet cap — token control, parity with ImapMailboxSearcher. */
    private const SNIPPET_CHARS = 2000;

    public function __construct(
        private GraphClient $graph,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string      $query keyword(s), free-text
     * @param string|null $from  optional sender filter (name or address)
     * @param string|null $since optional ISO date (YYYY-MM-DD) lower bound
     *
     * @return list<array{from: string, subject: string, date: string, snippet: string}> newest first
     *
     * @throws \RuntimeException when the mailbox cannot be searched
     */
    public function search(Connection $connection, string $query, ?string $from = null, ?string $since = null, int $limit = 10): array
    {
        $messages = $this->graph->searchMessages($connection, $query, $from, $since, $limit);

        $hits = [];
        foreach ($messages as $index => $message) {
            $snippet = $message['preview'];

            if (0 === $index && '' !== $message['id']) {
                // Top hit only: swap the preview for the real body so a
                // downstream summarize node has content to work with. A
                // failed body fetch degrades to the preview, never the search.
                try {
                    $body = $this->graph->messageBody($connection, $message['id'])['body'];
                    if ('' !== $body) {
                        $snippet = $body;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('GraphMailboxSearcher: body fetch for top hit failed, keeping preview', [
                        'connection_id' => $connection->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $hits[] = [
                'from' => $message['from'],
                'subject' => $message['subject'],
                'date' => $message['receivedAt'],
                'snippet' => mb_substr($snippet, 0, self::SNIPPET_CHARS),
            ];
        }

        return $hits;
    }
}
