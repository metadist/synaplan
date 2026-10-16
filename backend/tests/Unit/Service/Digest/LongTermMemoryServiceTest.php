<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Digest;

use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Service\Digest\LongTermMemoryService;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Digest\MessageDigestMaintenance;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class LongTermMemoryServiceTest extends TestCase
{
    private const USER_ID = 7;

    private MessageDigestRepository&MockObject $digestRepository;
    private MessageDigestConfig&Stub $config;
    private LongTermMemoryService $service;

    protected function setUp(): void
    {
        $this->digestRepository = $this->createMock(MessageDigestRepository::class);
        $this->config = $this->createStub(MessageDigestConfig::class);
        $this->service = new LongTermMemoryService(
            $this->digestRepository,
            $this->createStub(MessageDigestMaintenance::class),
            $this->config,
        );
    }

    public function testPageAndLimitAreClampedBeforeTheQuery(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->digestRepository->expects(self::once())
            ->method('findActivePage')
            ->with(self::USER_ID, LongTermMemoryService::MAX_LIMIT, 200)
            ->willReturn([]);
        $this->digestRepository->method('countActiveForUser')->willReturn(9);

        $result = $this->service->listEntries($this->user(memoriesEnabled: false), 3, 500);

        self::assertFalse($result['enabled']);
        self::assertFalse($result['memoriesEnabled']);
        self::assertSame([], $result['entries']);
        self::assertSame(9, $result['total']);
        self::assertSame(3, $result['page']);
        self::assertSame(LongTermMemoryService::MAX_LIMIT, $result['limit']);
    }

    public function testPageAndLimitBelowOneClampToTheMinimum(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->digestRepository->expects(self::once())
            ->method('findActivePage')
            ->with(self::USER_ID, LongTermMemoryService::MIN_LIMIT, 0)
            ->willReturn([]);
        $this->digestRepository->method('countActiveForUser')->willReturn(0);

        $result = $this->service->listEntries($this->user(), 0, 0);

        self::assertSame(LongTermMemoryService::MIN_PAGE, $result['page']);
        self::assertSame(LongTermMemoryService::MIN_LIMIT, $result['limit']);
        self::assertTrue($result['memoriesEnabled']);
    }

    public function testPlaceholderAndMissingChatTitlesBecomeNull(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->digestRepository->method('countActiveForUser')->willReturn(4);
        $this->digestRepository->expects(self::once())->method('findActivePage')->willReturn([
            $this->row(1, 5, 'New Chat'),
            $this->row(2, 6, 'Chat 9'),
            $this->row(3, 0, null),
            $this->row(4, 8, 'Q3 planning'),
        ]);

        $entries = $this->service->listEntries($this->user(), 1, 25)['entries'];

        self::assertSame([null, null, null, 'Q3 planning'], array_column($entries, 'chatTitle'));
        self::assertSame([5, 6, null, 8], array_column($entries, 'chatId'));
    }

    public function testExportKeepsListOrderAndNullsAMissingChat(): void
    {
        $this->digestRepository->expects(self::once())->method('findActiveForExport')->willReturn([
            ['title' => 'newer', 'messageId' => 20, 'chatId' => 4, 'channel' => 'web', 'sourceDate' => 200],
            ['title' => 'older', 'messageId' => 10, 'chatId' => 0, 'channel' => 'email', 'sourceDate' => 100],
        ]);

        self::assertSame([
            ['title' => 'newer', 'messageId' => 20, 'chatId' => 4, 'channel' => 'web', 'sourceDate' => 200],
            ['title' => 'older', 'messageId' => 10, 'chatId' => null, 'channel' => 'email', 'sourceDate' => 100],
        ], $this->service->exportEntries(self::USER_ID));
    }

    private function user(bool $memoriesEnabled = true): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::USER_ID);
        $user->method('isMemoriesEnabled')->willReturn($memoriesEnabled);

        return $user;
    }

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     messageId: int,
     *     chatId: int,
     *     channel: string,
     *     sourceDate: int,
     *     created: int,
     *     chatTitle: string|null
     * }
     */
    private function row(int $id, int $chatId, ?string $chatTitle): array
    {
        return [
            'id' => $id,
            'title' => 'digest '.$id,
            'messageId' => $id * 10,
            'chatId' => $chatId,
            'channel' => 'web',
            'sourceDate' => 1_700_000_000,
            'created' => 1_700_000_100,
            'chatTitle' => $chatTitle,
        ];
    }
}
