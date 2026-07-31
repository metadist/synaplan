<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\LocalAi;

use App\Service\LocalAi\LocalAiDownloadStatusService;
use PHPUnit\Framework\TestCase;

/**
 * The status file is written by a shell script in another container, so this
 * service has to survive every shape a half-written or abandoned file can take.
 */
final class LocalAiDownloadStatusServiceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/ollama-download-'.uniqid('', true).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function service(): LocalAiDownloadStatusService
    {
        return new LocalAiDownloadStatusService($this->path);
    }

    private function write(string $contents): void
    {
        file_put_contents($this->path, $contents);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(array $payload): void
    {
        $this->write(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    public function testMissingFileMeansIdle(): void
    {
        $status = $this->service()->getStatus();

        self::assertSame(LocalAiDownloadStatusService::STATUS_IDLE, $status['status']);
        self::assertSame([], $status['models']);
        self::assertNull($status['percent']);
        self::assertFalse($this->service()->isActivelyDownloading());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableContentsProvider(): iterable
    {
        yield 'empty file' => [''];
        yield 'whitespace only' => ["  \n"];
        // The entrypoint writes with a plain redirect, so a reader can catch the
        // file mid-write.
        yield 'truncated json' => ['{"status":"downloa'];
        yield 'json scalar instead of object' => ['"downloading"'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableContentsProvider')]
    public function testUnusableContentsFallBackToIdle(string $contents): void
    {
        $this->write($contents);

        self::assertSame(LocalAiDownloadStatusService::STATUS_IDLE, $this->service()->getStatus()['status']);
    }

    public function testReportsProgressOfARunningPull(): void
    {
        $this->writeJson([
            'status' => 'downloading',
            'currentModel' => 'bge-m3',
            'percent' => 40,
            'message' => 'pulling bge-m3',
            'models' => [
                ['name' => 'bge-m3', 'state' => 'downloading', 'percent' => 40],
            ],
            'updatedAt' => $this->now(),
        ]);

        $status = $this->service()->getStatus();

        self::assertSame('downloading', $status['status']);
        self::assertSame('bge-m3', $status['currentModel']);
        self::assertSame(40, $status['percent']);
        self::assertSame([['name' => 'bge-m3', 'state' => 'downloading', 'percent' => 40]], $status['models']);
        self::assertTrue($this->service()->isActivelyDownloading());
    }

    public function testCoercesNumericStringsAndDefaultsMissingModelState(): void
    {
        $this->writeJson([
            'status' => 'downloading',
            'percent' => '75',
            'models' => [
                ['name' => 'gpt-oss:20b', 'percent' => '75'],
                ['state' => 'downloading'], // no name — unusable, must be dropped
                'not-an-object',
            ],
            'updatedAt' => $this->now(),
        ]);

        $status = $this->service()->getStatus();

        self::assertSame(75, $status['percent']);
        self::assertSame([['name' => 'gpt-oss:20b', 'state' => 'unknown', 'percent' => 75]], $status['models']);
    }

    /**
     * A pull that died with its container leaves "downloading" in the file
     * forever. Reporting it would show the user a progress bar that never moves.
     */
    public function testStaleInProgressStatusIsTreatedAsIdle(): void
    {
        $this->writeJson([
            'status' => 'downloading',
            'percent' => 30,
            'updatedAt' => (new \DateTimeImmutable('-3 hours'))->format(\DateTimeInterface::ATOM),
        ]);

        self::assertSame(LocalAiDownloadStatusService::STATUS_IDLE, $this->service()->getStatus()['status']);
        self::assertFalse($this->service()->isActivelyDownloading());
    }

    public function testInProgressStatusWithoutTimestampIsTreatedAsIdle(): void
    {
        $this->writeJson(['status' => 'waiting']);

        self::assertSame(LocalAiDownloadStatusService::STATUS_IDLE, $this->service()->getStatus()['status']);
    }

    /**
     * Terminal states have no timestamp semantics — a pull that finished last
     * week is still finished.
     */
    public function testTerminalStatesAreReportedRegardlessOfAge(): void
    {
        $old = (new \DateTimeImmutable('-7 days'))->format(\DateTimeInterface::ATOM);

        $this->writeJson(['status' => 'ready', 'updatedAt' => $old]);
        self::assertSame('ready', $this->service()->getStatus()['status']);

        $this->writeJson(['status' => 'error', 'message' => 'ollama unreachable', 'updatedAt' => $old]);
        $status = $this->service()->getStatus();
        self::assertSame('error', $status['status']);
        self::assertSame('ollama unreachable', $status['message']);
        self::assertFalse($this->service()->isActivelyDownloading());
    }
}
