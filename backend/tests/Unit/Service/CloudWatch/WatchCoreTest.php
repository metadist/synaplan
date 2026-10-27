<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\CloudWatch;

use App\Command\SeedCloudWatchesCommand;
use App\Entity\CloudWatch;
use App\Repository\CloudDeliveryRepository;
use App\Service\CloudWatch\LoopGuard;
use App\Service\CloudWatch\QuietPeriod;
use App\Service\CloudWatch\WatchIndexCursor;
use App\Service\CloudWatch\WatchLimits;
use PHPUnit\Framework\TestCase;

final class WatchCoreTest extends TestCase
{
    public function testAFileIsQuietAfterSixtySeconds(): void
    {
        $quiet = new QuietPeriod();

        self::assertFalse($quiet->due(1_000, 1_059));
        self::assertTrue($quiet->due(1_000, 1_060));
    }

    public function testTheCapSentencesNameTheNumber(): void
    {
        $limits = new WatchLimits();

        self::assertSame(
            'You already watch 20 folders and files with Synaplan, the most allowed. Stop one in Tasks → Watched folders to start another.',
            $limits->personSentence(20),
        );
        self::assertSame(
            'files.example.org already has 1 000 watched folders and files, the most Synaplan allows. Ask your administrator.',
            $limits->instanceSentence('files.example.org', 1000),
        );
        self::assertSame(500, $limits->perHour(true));
        self::assertSame(100, $limits->perHour(false));
        self::assertFalse($limits->readable('photo.png', false));
        self::assertTrue($limits->readable('notes.md', false));
    }

    public function testACursorOlderThanSevenDaysIsRefused(): void
    {
        $now = 1_700_000_000;
        $fresh = WatchIndexCursor::since($now - 10, 5);
        $stale = WatchIndexCursor::since($now - WatchIndexCursor::TTL_SECONDS - 1, 5);

        self::assertSame(['iat' => $now - 10, 'kind' => 's', 'revision' => 5, 'id' => 0], WatchIndexCursor::read($fresh, $now));
        self::assertNull(WatchIndexCursor::read($stale, $now));
        self::assertNull(WatchIndexCursor::read('not-a-cursor', $now));
    }

    public function testTheLoopGuardDropsOwnWritesAndTranslatedNames(): void
    {
        $deliveries = $this->createStub(CloudDeliveryRepository::class);
        $deliveries->method('writtenRecently')->willReturnCallback(
            static fn (int $identity, string $remote): bool => '5678' === $remote,
        );
        $guard = new LoopGuard($deliveries);
        $watch = new CloudWatch(1, CloudWatch::KIND_FOLDER, 'incoming');
        $watch->setIdentityId(4);
        $watch->setPreset(CloudWatch::PRESET_TRANSLATE);
        $watch->setFilter(['language' => 'en']);

        self::assertSame('own_write', $guard->reason($watch, '5678', 'report.docx', false, 1_000));
        self::assertSame('language_suffix', $guard->reason($watch, '999', 'report.en.docx', false, 1_000));
        self::assertSame('own_write', $guard->reason($watch, '999', 'report.docx', true, 1_000));
        self::assertNull($guard->reason($watch, '999', 'report.docx', false, 1_000));
    }

    public function testTheSeedCommandRefusesProduction(): void
    {
        self::assertTrue(SeedCloudWatchesCommand::allowed('dev'));
        self::assertTrue(SeedCloudWatchesCommand::allowed('test'));
        self::assertFalse(SeedCloudWatchesCommand::allowed('prod'));
    }
}
