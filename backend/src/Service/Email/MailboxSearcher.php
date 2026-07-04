<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Entity\InboundEmailHandler;

/**
 * Read-only live search over one connected mailbox (release-4.0 plan 09
 * §3.3, shape (a): stateless IMAP search at question time — mail content is
 * never persisted, it exists only in the turn context).
 *
 * Implementations MUST be strictly read-only: peek fetches, no flag changes,
 * no deletes.
 */
interface MailboxSearcher
{
    /**
     * @param string      $query keyword(s) for the IMAP TEXT criterion
     * @param string|null $from  optional sender filter (FROM criterion)
     * @param string|null $since optional ISO date (YYYY-MM-DD) lower bound
     *
     * @return list<array{from: string, subject: string, date: string, snippet: string}> newest first
     *
     * @throws \RuntimeException when the mailbox cannot be reached/searched
     */
    public function search(InboundEmailHandler $handler, string $query, ?string $from = null, ?string $since = null, int $limit = 10): array;
}
