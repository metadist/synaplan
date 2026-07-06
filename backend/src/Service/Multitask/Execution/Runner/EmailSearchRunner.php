<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\InboundEmailHandler;
use App\Repository\InboundEmailHandlerRepository;
use App\Service\Email\MailboxSearcher;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use Psr\Log\LoggerInterface;

/**
 * `email_search` runner — "search for infos in my emails" as a DAG data node
 * (release-4.0 plan 09 §3.3, locked shape (a): stateless LIVE IMAP search at
 * question time; nothing is indexed or persisted).
 *
 * Reuses the user's active {@see InboundEmailHandler} accounts (encrypted
 * creds, existing connection settings) via the read-only
 * {@see MailboxSearcher}. Multi-account: every active mailbox is searched,
 * hits merged newest-first and capped.
 *
 * A DYNAMIC skill block: the planner only learns this capability exists when
 * the flag is on AND the user actually has a connected mailbox — a user
 * without one never sees it, so the planner cannot plan around a missing
 * account (plan 09 §3.3 availability note).
 */
final readonly class EmailSearchRunner implements TaskRunner
{
    /** Max merged hits across all accounts (token control). */
    private const MAX_RESULTS = 10;

    /** Per-mailbox hard time budget: a dead IMAP server degrades one node, not the turn. */
    private const PER_MAILBOX_TIMEOUT_SECONDS = 15;

    public function __construct(
        private InboundEmailHandlerRepository $handlers,
        private MailboxSearcher $searcher,
        private MultitaskRoutingConfig $routingConfig,
        private LoggerInterface $logger,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::EmailSearch];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::EmailSearch,
                'Search the user\'s OWN connected email mailbox(es) live (read-only) and return the newest matching mails. Use ONLY when the user explicitly asks about their own emails ("in my emails", "what did X mail me about …"). params: query (keywords), optional from (sender), optional since (YYYY-MM-DD).',
                dynamicNote: fn (?int $userId, array $context): ?string => $this->renderAvailabilityNote($userId),
                enabledFlag: MultitaskRoutingConfig::KEY_EMAIL_SEARCH_ENABLED,
                enabledDefault: false,
                requiresDynamicNote: true,
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $userId = $context->userId ?? $context->message->getUserId();

        if (!$this->routingConfig->isFeatureEnabled(MultitaskRoutingConfig::KEY_EMAIL_SEARCH_ENABLED, $userId, false)) {
            return NodeResult::failed('email_search is disabled');
        }

        $accounts = $this->handlers->findActiveByUser((int) $userId);
        if ([] === $accounts) {
            return NodeResult::failed('no email account is connected — connect one under Channels → Email Automation');
        }

        $inputs = $context->resolveInputs($node);
        $query = $this->stringValue($node->params['query'] ?? null)
            ?? $this->stringValue($inputs['query'] ?? $inputs['text'] ?? null)
            ?? (string) $context->message->getText();
        if ('' === trim($query)) {
            return NodeResult::failed('no search query for email_search');
        }

        $from = $this->stringValue($node->params['from'] ?? null);
        $since = $this->stringValue($node->params['since'] ?? null);

        $hits = [];
        $errors = [];
        $deadline = time() + self::PER_MAILBOX_TIMEOUT_SECONDS * count($accounts);
        foreach ($accounts as $account) {
            if (time() > $deadline) {
                break;
            }
            try {
                foreach ($this->searcher->search($account, $query, $from, $since, self::MAX_RESULTS) as $hit) {
                    $hit['account'] = $account->getName();
                    $hits[] = $hit;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('EmailSearchRunner: mailbox search failed', [
                    'handler_id' => $account->getId(),
                    'error' => $e->getMessage(),
                ]);
                $errors[] = $account->getName().': '.$e->getMessage();
            }
        }

        if ([] === $hits) {
            if ([] !== $errors && count($errors) === count($accounts)) {
                return NodeResult::failed('could not search the mailbox: '.mb_substr(implode('; ', $errors), 0, 300));
            }

            return NodeResult::ok(
                sprintf('No emails matching "%s" were found in the connected mailbox(es).', $query),
                [],
                ['email_search' => true, 'query' => $query, 'results_count' => 0],
            );
        }

        // Merge newest first across accounts, cap the total.
        usort($hits, static fn (array $a, array $b): int => strtotime($b['date'] ?: 'now') <=> strtotime($a['date'] ?: 'now'));
        $hits = array_slice($hits, 0, self::MAX_RESULTS);

        return NodeResult::ok($this->format($query, $hits), [], [
            'email_search' => true,
            'query' => $query,
            'results_count' => count($hits),
        ]);
    }

    /**
     * Availability note for the planner catalog — null (block invisible)
     * when the user has no active mailbox.
     */
    private function renderAvailabilityNote(?int $userId): ?string
    {
        if (null === $userId || $userId <= 0) {
            return null;
        }

        try {
            $accounts = $this->handlers->findActiveByUser($userId);
        } catch (\Throwable) {
            return null;
        }
        if ([] === $accounts) {
            return null;
        }

        $names = array_map(
            static fn (InboundEmailHandler $h): string => '"'.$h->getName().'"',
            array_slice($accounts, 0, 5),
        );

        return sprintf('  Connected mailbox(es) for this user: %s.', implode(', ', $names));
    }

    /**
     * Citable, web-search-style sections for downstream `$nX.text` consumers.
     *
     * @param list<array{from: string, subject: string, date: string, snippet: string, account?: string}> $hits
     */
    private function format(string $query, array $hits): string
    {
        $sections = [sprintf('## Email search results for "%s" (%d found, newest first)', $query, count($hits))];
        foreach ($hits as $i => $hit) {
            $section = sprintf(
                "--- Email %d ---\nFrom: %s\nDate: %s\nSubject: %s",
                $i + 1,
                $hit['from'],
                $hit['date'],
                $hit['subject'],
            );
            if ('' !== trim($hit['snippet'])) {
                $section .= "\n".$hit['snippet'];
            }
            $sections[] = $section;
        }

        return implode("\n\n", $sections);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
