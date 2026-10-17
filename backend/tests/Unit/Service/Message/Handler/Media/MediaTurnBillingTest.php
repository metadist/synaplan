<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Message\Handler\Media\MediaModel;
use App\Service\Message\Handler\Media\MediaReferences;
use App\Service\Message\Handler\Media\MediaTurnBilling;
use App\Service\Message\Handler\Media\MediaTurnRequest;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MediaTurnBillingTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $recorded = [];

    public function testAPictureIsBilledAtItsQualityAndRenderedSize(): void
    {
        self::assertSame(
            [
                ['images' => 1, 'quality' => 'standard', 'size' => '1024x1024'],
                ['images' => 2, 'quality' => 'high', 'size' => '1536x1024'],
                ['images' => 1, 'quality' => 'hd', 'size' => '512x512'],
            ],
            [
                $this->billing()->usageOf($this->request('image'), [], [[]]),
                $this->billing()->usageOf($this->request('image', attached: true), ['image_count' => 2], [['size' => '1536x1024']]),
                $this->billing()->usageOf($this->request('image', options: ['quality' => 'hd', 'size' => '512x512']), [], [[]]),
            ],
        );
    }

    public function testAVideoIsBilledInSecondsAndFallsBackToTheRequestedLength(): void
    {
        self::assertSame(
            [
                ['duration_seconds' => 5.0, 'resolution' => '720p'],
                ['duration_missing_fallback' => true, 'duration_seconds' => 6.0, 'resolution' => '1080p'],
            ],
            [
                $this->billing()->usageOf($this->request('video'), ['duration_seconds' => 5.0, 'resolution' => '720p'], []),
                $this->billing()->usageOf($this->request('video', options: ['duration' => 6]), ['videos' => [['resolution' => '1080p']]], []),
            ],
        );
    }

    public function testOnlyACallerThatOwnsTheCostRecordsHere(): void
    {
        $user = $this->createStub(User::class);

        self::assertNull($this->billing()->record($user, $this->request('image'), 'openai', 'gpt-image-1', ['images' => 1]));
        self::assertNull($this->billing()->record(null, $this->request('image', options: ['record_media_usage' => true]), 'openai', 'gpt-image-1', ['images' => 1]));
        self::assertInstanceOf(RecordedUsage::class, $this->billing()->record($user, $this->request('image', options: ['record_media_usage' => true]), 'openai', 'gpt-image-1', ['images' => 1]));
        self::assertSame([['IMAGES', ['provider' => 'openai', 'model' => 'gpt-image-1', 'model_id' => 41, 'media_usage' => ['images' => 1]]]], $this->recorded);
    }

    public function testAFailedRecordNeverFailsTheTurn(): void
    {
        self::assertNull($this->billing(fails: true)->record($this->createStub(User::class), $this->request('image', options: ['record_media_usage' => true]), 'openai', null, []));
    }

    public function testAStopIsBilledFromTheRequest(): void
    {
        self::assertSame(
            [['duration_seconds' => 6.0, 'resolution' => '1080p'], ['characters' => 9], ['images' => 1]],
            [
                $this->billing()->cancelledUsage($this->request('video', options: ['resolution' => '1080p'], classification: ['duration' => 6]), null),
                $this->billing()->cancelledUsage($this->request('audio', classification: ['media_prompt' => 'Good luck']), null),
                $this->billing()->cancelledUsage($this->request('image'), null),
            ],
        );
    }

    private function billing(bool $fails = false): MediaTurnBilling
    {
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('recordUsage')->willReturnCallback(function (User $user, string $action, array $metadata) use ($fails): RecordedUsage {
            if ($fails) {
                throw new \RuntimeException('The usage log is unreachable.');
            }
            $this->recorded[] = [$action, $metadata];

            return new RecordedUsage('0.040000', '0.040000', 0, 0, 0);
        });

        return new MediaTurnBilling($limits, new NullLogger());
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $classification
     */
    private function request(string $mediaType, array $options = [], array $classification = [], bool $attached = false): MediaTurnRequest
    {
        return new MediaTurnRequest($this->createStub(Message::class), [], $classification, $options, 'A fox', $mediaType, new MediaReferences($attached ? ['/uploads/photo.png'] : [], [], null), new MediaModel(41, 'openai', 'gpt-image-1', []), 7);
    }
}
