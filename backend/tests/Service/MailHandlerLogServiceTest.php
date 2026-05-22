<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\UseLog;
use App\Repository\MailHandlerLogRepository;
use App\Service\MailHandlerLogService;
use Doctrine\DBAL\Exception\InvalidArgumentException as DbalInvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MailHandlerLogServiceTest extends TestCase
{
    private MailHandlerLogService $service;
    private MailHandlerLogRepository&MockObject $repository;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(MailHandlerLogRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MailHandlerLogService($this->repository, $this->logger);
    }

    public function testLogPersistsEntryViaRepositoryWithCanonicalShape(): void
    {
        $captured = null;

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(
                function (int $userId, int $handlerId, UseLog $entry) use (&$captured): void {
                    $captured = ['user' => $userId, 'handler' => $handlerId, 'entry' => $entry];
                }
            );

        $this->service->log(
            42,
            7,
            MailHandlerLogService::EVENT_FORWARDED,
            MailHandlerLogService::STATUS_SUCCESS,
            null,
            ['from' => 'alice@example.com', 'subject' => 'Hello', 'routed_to' => 'sales@acme.io']
        );

        $this->assertNotNull($captured);
        $this->assertSame(42, $captured['user']);
        $this->assertSame(7, $captured['handler']);

        /** @var UseLog $entry */
        $entry = $captured['entry'];
        $this->assertSame(42, $entry->getUserId());
        $this->assertSame(MailHandlerLogService::STATUS_SUCCESS, $entry->getStatus());
        $this->assertSame('', $entry->getError());

        $metadata = $entry->getMetadata();
        $this->assertSame(MailHandlerLogService::EVENT_FORWARDED, $metadata['event']);
        $this->assertSame('alice@example.com', $metadata['from']);
        $this->assertSame('sales@acme.io', $metadata['routed_to']);
        // handler_id lives in BPROVIDER (set by the repository) — it must
        // NOT be duplicated into BMETADATA where it would be unindexable.
        $this->assertArrayNotHasKey('handler_id', $metadata);
    }

    public function testLogStoresErrorAndCoercesUnknownStatus(): void
    {
        $captured = null;

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(
                function (int $userId, int $handlerId, UseLog $entry) use (&$captured): void {
                    $captured = $entry;
                }
            );

        $this->service->log(
            42,
            7,
            MailHandlerLogService::EVENT_FORWARD_FAILED,
            'totally-not-a-real-status',
            'SMTP server refused the connection',
            ['routed_to' => 'sales@acme.io']
        );

        $this->assertNotNull($captured);
        $this->assertSame(MailHandlerLogService::STATUS_SUCCESS, $captured->getStatus());
        $this->assertSame('SMTP server refused the connection', $captured->getError());
    }

    public function testLogAcceptsWarningStatusVerbatim(): void
    {
        $captured = null;

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(
                function (int $userId, int $handlerId, UseLog $entry) use (&$captured): void {
                    $captured = $entry;
                }
            );

        $this->service->log(
            42,
            7,
            MailHandlerLogService::EVENT_NO_SMTP,
            MailHandlerLogService::STATUS_WARNING,
            'SMTP not configured',
        );

        $this->assertNotNull($captured);
        $this->assertSame(MailHandlerLogService::STATUS_WARNING, $captured->getStatus());
    }

    public function testLogTruncatesLongFreeTextFields(): void
    {
        $captured = null;

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(
                function (int $userId, int $handlerId, UseLog $entry) use (&$captured): void {
                    $captured = $entry;
                }
            );

        $longSubject = str_repeat('A', 1000);
        $longError = str_repeat('B', 1000);

        $this->service->log(
            42,
            7,
            MailHandlerLogService::EVENT_PROCESS_ERROR,
            MailHandlerLogService::STATUS_ERROR,
            $longError,
            ['subject' => $longSubject]
        );

        $this->assertNotNull($captured);
        $this->assertLessThanOrEqual(256, mb_strlen($captured->getError()));

        $metadata = $captured->getMetadata();
        $this->assertIsString($metadata['subject']);
        $this->assertLessThanOrEqual(256, mb_strlen($metadata['subject']));
        $this->assertStringEndsWith('…', $metadata['subject']);
    }

    public function testLogStripsReservedDetailKeys(): void
    {
        $captured = null;

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(
                function (int $userId, int $handlerId, UseLog $entry) use (&$captured): void {
                    $captured = $entry;
                }
            );

        // Even if callers accidentally pass `handler_id` / `event`
        // through details, the service must not double-store them in
        // metadata — they come exclusively from the explicit parameters
        // (and `handler_id` is stored in BPROVIDER by the repository).
        $this->service->log(
            42,
            7,
            MailHandlerLogService::EVENT_FORWARDED,
            MailHandlerLogService::STATUS_SUCCESS,
            null,
            ['handler_id' => 999, 'event' => 'fake', 'from' => 'alice@example.com'],
        );

        $this->assertNotNull($captured);
        $metadata = $captured->getMetadata();
        $this->assertArrayNotHasKey('handler_id', $metadata);
        $this->assertSame(MailHandlerLogService::EVENT_FORWARDED, $metadata['event']);
        $this->assertSame('alice@example.com', $metadata['from']);
    }

    public function testLogSwallowsRepositoryExceptions(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('save')
            ->willThrowException(new DbalInvalidArgumentException('connection lost'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'MailHandlerLogService: failed to persist activity entry',
                $this->callback(fn ($ctx) => isset($ctx['error']) && str_contains($ctx['error'], 'connection lost'))
            );

        // Must not bubble — broken activity log cannot break the mail handler run.
        $this->service->log(42, 7, MailHandlerLogService::EVENT_CHECK);
    }

    public function testFindRecentDecodesMetadataAndStripsReservedKeys(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findRecent')
            ->with(11, 5, 10)
            ->willReturn([
                [
                    'id' => 101,
                    'unix_time' => 1747900000,
                    'status' => 'success',
                    'error' => '',
                    'metadata' => json_encode([
                        'event' => MailHandlerLogService::EVENT_FORWARDED,
                        'from' => 'alice@example.com',
                        'subject' => 'Hi',
                        'routed_to' => 'sales@acme.io',
                    ]),
                ],
                [
                    'id' => 99,
                    'unix_time' => 1747890000,
                    'status' => 'warning',
                    'error' => 'No SMTP creds',
                    'metadata' => json_encode([
                        'event' => MailHandlerLogService::EVENT_NO_SMTP,
                    ]),
                ],
            ]);

        $rows = $this->service->findRecent(11, 5);

        $this->assertCount(2, $rows);
        $this->assertSame(101, $rows[0]['id']);
        $this->assertSame(1747900000, $rows[0]['timestamp']);
        $this->assertSame(MailHandlerLogService::EVENT_FORWARDED, $rows[0]['event']);
        $this->assertSame('success', $rows[0]['status']);
        $this->assertSame('alice@example.com', $rows[0]['details']['from']);
        $this->assertArrayNotHasKey('event', $rows[0]['details']);

        $this->assertSame(99, $rows[1]['id']);
        $this->assertSame(MailHandlerLogService::EVENT_NO_SMTP, $rows[1]['event']);
        $this->assertSame('warning', $rows[1]['status']);
        $this->assertSame('No SMTP creds', $rows[1]['error']);
    }

    public function testFindRecentClampsLimitToSafeRange(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findRecent')
            ->with(11, 5, 100)
            ->willReturn([]);

        // Caller asks for 5_000 — must be clamped to 100.
        $this->service->findRecent(11, 5, 5_000);
    }

    public function testFindRecentReturnsEmptyOnRepositoryException(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findRecent')
            ->willThrowException(new DbalInvalidArgumentException('boom'));

        $this->logger
            ->expects($this->once())
            ->method('warning');

        $this->assertSame([], $this->service->findRecent(11, 5));
    }

    public function testPruneForwardsKeepLimit(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('prune')
            ->with(11, 5, 10)
            ->willReturn(3);

        $this->assertSame(3, $this->service->prune(11, 5));
    }

    public function testPruneCoercesNonPositiveKeepToOne(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('prune')
            ->with(11, 5, 1)
            ->willReturn(0);

        $this->service->prune(11, 5, 0);
    }

    public function testPruneSwallowsRepositoryExceptionsAndReturnsZero(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('prune')
            ->willThrowException(new DbalInvalidArgumentException('boom'));

        $this->logger
            ->expects($this->once())
            ->method('warning');

        $this->assertSame(0, $this->service->prune(11, 5));
    }

    public function testDeleteAllForwardsToRepository(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('deleteAll')
            ->with(11, 5)
            ->willReturn(7);

        $this->assertSame(7, $this->service->deleteAll(11, 5));
    }

    public function testDeleteAllSwallowsRepositoryExceptionsAndReturnsZero(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('deleteAll')
            ->willThrowException(new DbalInvalidArgumentException('boom'));

        $this->logger
            ->expects($this->once())
            ->method('warning');

        $this->assertSame(0, $this->service->deleteAll(11, 5));
    }
}
