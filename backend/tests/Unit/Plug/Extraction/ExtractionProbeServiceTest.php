<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug\Extraction;

use App\Plug\Extraction\ExtractionProbeService;
use App\Service\File\FileProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ExtractionProbeServiceTest extends TestCase
{
    public function testForwardsUserIdAndUsesFileProcessorAttempts(): void
    {
        $fileProcessor = $this->createMock(FileProcessor::class);
        $fileProcessor->expects(self::once())
            ->method('extractText')
            ->with(self::isString(), 'mp3', 42)
            ->willReturn([
                'hello from cloud stt',
                [
                    'strategy' => 'whisper_api',
                    'attempts' => [
                        ['key' => 'stt_cloud', 'verdict' => 'quality_ok', 'ms' => 12],
                    ],
                ],
            ]);

        $uploadDir = sys_get_temp_dir().'/synaplan-probe-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($uploadDir, 0775, true) || is_dir($uploadDir));

        $probe = new ExtractionProbeService($fileProcessor, new NullLogger(), $uploadDir);
        $path = tempnam(sys_get_temp_dir(), 'probe-mp3-');
        self::assertNotFalse($path);
        file_put_contents($path, 'audio');

        try {
            $result = $probe->testFile($path, 'speech.mp3', 42);
        } finally {
            @unlink($path);
            foreach (glob($uploadDir.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($uploadDir);
        }

        self::assertSame('whisper_api', $result['winner']);
        self::assertSame('whisper_api', $result['strategy']);
        self::assertSame('stt_cloud', $result['attempts'][0]['key'] ?? null);
        self::assertSame('hello from cloud stt', $result['preview']);
    }

    public function testMissingAttemptsFallsBackToStrategyRow(): void
    {
        $fileProcessor = $this->createMock(FileProcessor::class);
        $fileProcessor->method('extractText')->willReturn([
            'Tika body',
            ['strategy' => 'tika'],
        ]);

        $uploadDir = sys_get_temp_dir().'/synaplan-probe-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($uploadDir, 0775, true) || is_dir($uploadDir));

        $probe = new ExtractionProbeService($fileProcessor, new NullLogger(), $uploadDir);
        $path = tempnam(sys_get_temp_dir(), 'probe-md-');
        self::assertNotFalse($path);
        file_put_contents($path, 'md');

        try {
            $result = $probe->testFile($path, 'note.md', 1);
        } finally {
            @unlink($path);
            foreach (glob($uploadDir.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($uploadDir);
        }

        self::assertSame('tika', $result['attempts'][0]['key'] ?? null);
        self::assertSame('quality_ok', $result['attempts'][0]['verdict']);
    }
}
