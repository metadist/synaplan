<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Connection;
use App\Entity\InboundEmailHandler;
use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Repository\ConnectionRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Service\Email\GraphMailboxSearcher;
use App\Service\Email\MailboxSearcher;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\EmailSearchRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `email_search` node contract (plan 09 §3.3): live read-only mailbox search,
 * flag-gated, honest no-account degradation, multi-account merge, and the
 * per-user availability note that keeps the block invisible without a
 * connected mailbox.
 */
final class EmailSearchRunnerTest extends TestCase
{
    private const USER_ID = 7;

    private function account(string $name = 'Work inbox', int $id = 5): InboundEmailHandler
    {
        $account = new InboundEmailHandler();
        $account->setUserId(self::USER_ID)->setName($name);
        (new \ReflectionProperty($account, 'id'))->setValue($account, $id);

        return $account;
    }

    private function m365Connection(string $name = 'Work M365', string $status = Connection::STATUS_CONNECTED): Connection
    {
        $connection = new Connection(self::USER_ID, Connection::TYPE_M365, $name);
        $connection->setStatus($status);

        return $connection;
    }

    /**
     * @param list<InboundEmailHandler>                                                            $accounts
     * @param list<array{from: string, subject: string, date: string, snippet: string}>|\Throwable $searchResult
     * @param list<Connection>                                                                     $connections
     * @param list<array{from: string, subject: string, date: string, snippet: string}>|\Throwable $graphResult
     */
    private function runner(
        array $accounts,
        array|\Throwable $searchResult = [],
        bool $flagEnabled = true,
        array $connections = [],
        array|\Throwable $graphResult = [],
    ): EmailSearchRunner {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 'EMAIL_SEARCH_ENABLED' === $setting ? ($flagEnabled ? '1' : '0') : null,
        );

        $handlers = $this->createMock(InboundEmailHandlerRepository::class);
        $handlers->expects(self::any())->method('findActiveByUser')->with(self::USER_ID)->willReturn($accounts);

        $searcher = $this->createMock(MailboxSearcher::class);
        if ($searchResult instanceof \Throwable) {
            $searcher->method('search')->willThrowException($searchResult);
        } else {
            $searcher->method('search')->willReturn($searchResult);
        }

        $connectionRepo = $this->createMock(ConnectionRepository::class);
        $connectionRepo->expects(self::any())->method('findByOwner')->with(self::USER_ID)->willReturn($connections);

        $graphSearcher = $this->createMock(GraphMailboxSearcher::class);
        if ($graphResult instanceof \Throwable) {
            $graphSearcher->method('search')->willThrowException($graphResult);
        } else {
            $graphSearcher->method('search')->willReturn($graphResult);
        }

        return new EmailSearchRunner($handlers, $searcher, $connectionRepo, $graphSearcher, new MultitaskRoutingConfig($configRepo), new NullLogger());
    }

    private function context(): NodeContext
    {
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn('search my emails for the acme offer');
        $message->method('getUserId')->willReturn(self::USER_ID);
        $message->method('getFiles')->willReturn(new ArrayCollection([]));
        $message->method('getFileText')->willReturn('');

        return new NodeContext($message, [], self::USER_ID, ['topic' => 'general']);
    }

    private function node(array $params = ['query' => 'Acme offer']): TaskNode
    {
        return new TaskNode('n1', Capability::EmailSearch, [], [], $params);
    }

    public function testReturnsFormattedNewestFirstHits(): void
    {
        $runner = $this->runner([$this->account()], [
            ['from' => 'sales@acme.com', 'subject' => 'Acme offer v2', 'date' => 'Tue, 16 Jun 2026 10:00:00 +0200', 'snippet' => 'Here is the updated offer with 10% discount.'],
            ['from' => 'sales@acme.com', 'subject' => 'Acme offer', 'date' => 'Mon, 08 Jun 2026 09:00:00 +0200', 'snippet' => 'First offer draft.'],
        ]);

        $result = $runner->run($this->node(), $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('Acme offer v2', (string) $result->text);
        self::assertStringContainsString('10% discount', (string) $result->text);
        self::assertSame(2, $result->metadata['results_count']);
        self::assertSame('Acme offer', $result->metadata['query']);
        // Newest first.
        $text = (string) $result->text;
        self::assertLessThan(strpos($text, 'First offer draft'), strpos($text, '10% discount'));
    }

    public function testDisabledFlagFailsTheNode(): void
    {
        $result = $this->runner([$this->account()], flagEnabled: false)->run($this->node(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('disabled', (string) $result->error);
    }

    public function testNoConnectedAccountDegradesHonestly(): void
    {
        $result = $this->runner([])->run($this->node(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('no email account is connected', (string) $result->error);
    }

    public function testNoHitsIsASuccessfulHonestAnswerNotAFailure(): void
    {
        $result = $this->runner([$this->account()], [])->run($this->node(), $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('No emails matching', (string) $result->text);
        self::assertSame(0, $result->metadata['results_count']);
    }

    public function testDeadMailboxFailsTheNodeInIsolation(): void
    {
        $result = $this->runner([$this->account()], new \RuntimeException('mailbox connection failed: timeout'))
            ->run($this->node(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('could not search the mailbox', (string) $result->error);
    }

    public function testAvailabilityNoteOnlyRendersWithAConnectedMailbox(): void
    {
        $withAccount = $this->runner([$this->account('Work inbox')]);
        $descriptor = $withAccount->describe()[0];
        self::assertTrue($descriptor->requiresDynamicNote);

        $note = ($descriptor->dynamicNote)(self::USER_ID, []);
        self::assertIsString($note);
        self::assertStringContainsString('"Work inbox"', $note);

        $without = $this->runner([]);
        self::assertNull(($without->describe()[0]->dynamicNote)(self::USER_ID, []));
    }

    // ---- Phase M step M3c: Microsoft 365 as a second mail source ----

    public function testM365OnlyUserCanSearchWithoutAnImapAccount(): void
    {
        $runner = $this->runner(
            [],
            connections: [$this->m365Connection()],
            graphResult: [
                ['from' => 'oliver@fps.test', 'subject' => 'FPSenergy final', 'date' => '2026-08-15T09:00:00Z', 'snippet' => 'the full body'],
            ],
        );

        $result = $runner->run($this->node(), $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('FPSenergy final', (string) $result->text);
        self::assertSame(1, $result->metadata['results_count']);
    }

    public function testHitsFromBothSourcesAreMergedNewestFirst(): void
    {
        $runner = $this->runner(
            [$this->account()],
            [
                ['from' => 'sales@acme.com', 'subject' => 'IMAP older', 'date' => 'Mon, 08 Jun 2026 09:00:00 +0200', 'snippet' => 'imap body'],
            ],
            connections: [$this->m365Connection()],
            graphResult: [
                ['from' => 'oliver@fps.test', 'subject' => 'Graph newer', 'date' => '2026-08-15T09:00:00Z', 'snippet' => 'graph body'],
            ],
        );

        $result = $runner->run($this->node(), $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertSame(2, $result->metadata['results_count']);
        $text = (string) $result->text;
        self::assertLessThan(strpos($text, 'IMAP older'), strpos($text, 'Graph newer'), 'merged hits must be newest first across sources');
    }

    public function testAFailingM365SourceDegradesOnlyItself(): void
    {
        $runner = $this->runner(
            [$this->account()],
            [
                ['from' => 'sales@acme.com', 'subject' => 'IMAP hit', 'date' => 'Mon, 08 Jun 2026 09:00:00 +0200', 'snippet' => 'imap body'],
            ],
            connections: [$this->m365Connection()],
            graphResult: new \RuntimeException('Graph answered HTTP 503'),
        );

        $result = $runner->run($this->node(), $this->context());

        self::assertTrue($result->isSuccessful(), 'one dead source must not kill the node when the other delivered');
        self::assertSame(1, $result->metadata['results_count']);
    }

    public function testDisconnectedAndReauthConnectionsAreNeverSearched(): void
    {
        $runner = $this->runner(
            [],
            connections: [
                $this->m365Connection('Gone', Connection::STATUS_DISCONNECTED),
                $this->m365Connection('Stale', Connection::STATUS_REAUTH_REQUIRED),
            ],
        );

        $result = $runner->run($this->node(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('no email account is connected', (string) $result->error);
    }

    public function testAvailabilityNoteListsTheM365Source(): void
    {
        $runner = $this->runner([], connections: [$this->m365Connection('Work M365')]);

        $note = ($runner->describe()[0]->dynamicNote)(self::USER_ID, []);

        self::assertIsString($note);
        self::assertStringContainsString('"Work M365" (Microsoft 365)', $note);
    }
}
