<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MailHandlerLogService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\InvalidArgumentException as DbalInvalidArgumentException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MailHandlerLogServiceTest extends TestCase
{
    private MailHandlerLogService $service;
    private Connection&MockObject $connection;
    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($this->connection);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MailHandlerLogService($this->em, $this->logger);
    }

    public function testLogPersistsRowWithCanonicalShape(): void
    {
        $captured = null;

        $this->connection
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $payload) use (&$captured): int {
                $this->assertSame('BUSELOG', $table);
                $captured = $payload;

                return 1;
            });

        $this->service->log(
            42,
            7,
            MailHandlerLogService::EVENT_FORWARDED,
            MailHandlerLogService::STATUS_SUCCESS,
            null,
            ['from' => 'alice@example.com', 'subject' => 'Hello', 'routed_to' => 'sales@acme.io']
        );

        $this->assertNotNull($captured);
        $this->assertSame(42, $captured['BUSERID']);
        $this->assertSame(MailHandlerLogService::ACTION, $captured['BACTION']);
        $this->assertSame(MailHandlerLogService::STATUS_SUCCESS, $captured['BSTATUS']);
        $this->assertSame('', $captured['BERROR']);
        $this->assertIsString($captured['BMETADATA']);

        $metadata = json_decode($captured['BMETADATA'], true);
        $this->assertIsArray($metadata);
        $this->assertSame(7, $metadata['handler_id']);
        $this->assertSame(MailHandlerLogService::EVENT_FORWARDED, $metadata['event']);
        $this->assertSame('alice@example.com', $metadata['from']);
        $this->assertSame('sales@acme.io', $metadata['routed_to']);
    }

    public function testLogStoresErrorAndCoercesUnknownStatus(): void
    {
        $captured = null;

        $this->connection
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $payload) use (&$captured): int {
                $captured = $payload;

                return 1;
            });

        $this->service->log(
            42,
            7,
            MailHandlerLogService::EVENT_FORWARD_FAILED,
            'totally-not-a-real-status',
            'SMTP server refused the connection',
            ['routed_to' => 'sales@acme.io']
        );

        $this->assertNotNull($captured);
        $this->assertSame(MailHandlerLogService::STATUS_SUCCESS, $captured['BSTATUS']);
        $this->assertSame('SMTP server refused the connection', $captured['BERROR']);
    }

    public function testLogTruncatesLongFreeTextFields(): void
    {
        $captured = null;

        $this->connection
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $payload) use (&$captured): int {
                $captured = $payload;

                return 1;
            });

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
        $this->assertLessThanOrEqual(256, mb_strlen($captured['BERROR']));

        $metadata = json_decode($captured['BMETADATA'], true);
        $this->assertIsArray($metadata);
        $this->assertLessThanOrEqual(256, mb_strlen($metadata['subject']));
        $this->assertStringEndsWith('…', $metadata['subject']);
    }

    public function testLogSwallowsDbalExceptions(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('insert')
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

    public function testFindRecentDecodesMetadataAndOrdering(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->stringContains('FROM BUSELOG'),
                $this->callback(function (array $params): bool {
                    return 11 === $params['user_id']
                        && MailHandlerLogService::ACTION === $params['action']
                        && 5 === $params['handler_id']
                        && 10 === $params['limit'];
                }),
                $this->callback(function (array $types): bool {
                    return ParameterType::INTEGER === $types['limit'];
                })
            )
            ->willReturn([
                [
                    'id' => '101',
                    'unix_time' => '1747900000',
                    'status' => 'success',
                    'error' => '',
                    'metadata' => json_encode([
                        'handler_id' => 5,
                        'event' => MailHandlerLogService::EVENT_FORWARDED,
                        'from' => 'alice@example.com',
                        'subject' => 'Hi',
                        'routed_to' => 'sales@acme.io',
                    ]),
                ],
                [
                    'id' => '99',
                    'unix_time' => '1747890000',
                    'status' => 'error',
                    'error' => 'No SMTP creds',
                    'metadata' => json_encode([
                        'handler_id' => 5,
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
        $this->assertArrayNotHasKey('handler_id', $rows[0]['details']);
        $this->assertArrayNotHasKey('event', $rows[0]['details']);

        $this->assertSame(99, $rows[1]['id']);
        $this->assertSame(MailHandlerLogService::EVENT_NO_SMTP, $rows[1]['event']);
        $this->assertSame('No SMTP creds', $rows[1]['error']);
    }

    public function testFindRecentClampsLimitToSafeRange(): void
    {
        $captured = null;

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params) use (&$captured): array {
                $captured = $params;

                return [];
            });

        // Caller asks for 5_000 — must be clamped to 100.
        $this->service->findRecent(11, 5, 5_000);

        $this->assertSame(100, $captured['limit']);
    }

    public function testFindRecentReturnsEmptyOnDbalException(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willThrowException(new DbalInvalidArgumentException('boom'));

        $this->logger
            ->expects($this->once())
            ->method('warning');

        $this->assertSame([], $this->service->findRecent(11, 5));
    }

    public function testPruneIssuesDeleteWithKeepLimit(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM BUSELOG'),
                $this->callback(function (array $params): bool {
                    return 11 === $params['user_id']
                        && MailHandlerLogService::ACTION === $params['action']
                        && 5 === $params['handler_id']
                        && 10 === $params['keep'];
                }),
                $this->callback(function (array $types): bool {
                    return ParameterType::INTEGER === $types['keep'];
                })
            )
            ->willReturn(3);

        $this->assertSame(3, $this->service->prune(11, 5));
    }

    public function testPruneCoercesNonPositiveKeepToOne(): void
    {
        $captured = null;

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$captured): int {
                $captured = $params;

                return 0;
            });

        $this->service->prune(11, 5, 0);

        $this->assertSame(1, $captured['keep']);
    }

    public function testDeleteAllIssuesUnboundedDelete(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('DELETE FROM BUSELOG'),
                    $this->logicalNot($this->stringContains('LIMIT'))
                ),
                $this->callback(function (array $params): bool {
                    return 11 === $params['user_id']
                        && MailHandlerLogService::ACTION === $params['action']
                        && 5 === $params['handler_id'];
                })
            )
            ->willReturn(7);

        $this->assertSame(7, $this->service->deleteAll(11, 5));
    }
}
