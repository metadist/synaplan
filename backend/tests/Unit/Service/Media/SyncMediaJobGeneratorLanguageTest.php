<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\AI\Service\AiFacade;
use App\Service\Media\MediaJob;
use App\Service\Media\SyncMediaJobGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Async audio jobs must carry an explicit language (#2283).
 */
final class SyncMediaJobGeneratorLanguageTest extends TestCase
{
    public function testGenerateAudioFailsLoudlyWithoutLanguage(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects(self::never())->method('synthesize');

        $generator = new SyncMediaJobGenerator(
            $aiFacade,
            $this->createMock(\App\Service\File\UserUploadPathBuilder::class),
            $this->createMock(HttpClientInterface::class),
            new NullLogger(),
            '/tmp',
        );

        $job = new MediaJob();
        $job->setUserId(7);
        $job->setType(MediaJob::TYPE_AUDIO);
        $job->setProvider('piper');
        $job->setPrompt('Hallo');
        $job->setOptions([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing language');

        $generator->generate($job);
    }

    public function testGenerateAudioPassesJobLanguageToFacade(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects(self::once())
            ->method('synthesize')
            ->with('Hallo Welt', 'de', 7, self::callback(static function (array $opts): bool {
                return 'piper' === ($opts['provider'] ?? null)
                    && 'mp3' === ($opts['format'] ?? null);
            }))
            ->willReturn([
                'relativePath' => '7/tts.mp3',
                'provider' => 'piper',
                'model' => 'de_DE-kerstin-low',
            ]);

        $generator = new SyncMediaJobGenerator(
            $aiFacade,
            $this->createMock(\App\Service\File\UserUploadPathBuilder::class),
            $this->createMock(HttpClientInterface::class),
            new NullLogger(),
            '/tmp',
        );

        $job = new MediaJob();
        $job->setUserId(7);
        $job->setType(MediaJob::TYPE_AUDIO);
        $job->setProvider('piper');
        $job->setModel('piper-multi');
        $job->setPrompt('Hallo Welt');
        $job->setOptions(['lang' => 'de']);

        $result = $generator->generate($job);

        self::assertSame('audio', $result['file']['type']);
        self::assertSame('/api/v1/files/uploads/7/tts.mp3', $result['file']['url']);
    }
}
