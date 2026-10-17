<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\Google;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Provider\Google\GoogleApiClient;
use App\AI\Provider\Google\GoogleOmniVideo;
use App\AI\Provider\Google\GoogleVideoGeneration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GoogleVideoGenerationTest extends TestCase
{
    use GoogleApiDouble;

    protected function tearDown(): void
    {
        set_time_limit(0);
    }

    public function testVeoGetsADurationAndAResolutionItAccepts(): void
    {
        $video = $this->video($this->api([self::json(['name' => 'operations/op-1'])]));

        $operation = $video->startVideoOperation('A lake at dawn', ['duration' => 5, 'resolution' => '4K', 'modelConfig' => ['allowed_resolutions' => ['1080p', '4K']]]);

        self::assertSame(['operationName' => 'operations/op-1', 'model' => 'veo-3.1-generate-preview', 'duration' => 4, 'resolution' => '4K'], $operation);
        self::assertSame(['durationSeconds' => 4, 'aspectRatio' => '16:9', 'resolution' => '4k'], $this->requests()[0]['body']['parameters']);
    }

    public function testOmniModelsAndOperationsGoToTheInteractionsApi(): void
    {
        $video = $this->video($this->api([self::json(['id' => 'int-1'], 201), self::json([])]));

        $operation = $video->startVideoOperation('A cat', ['model' => 'gemini-omni-flash']);
        $video->cancelVideoOperation($operation['operationName']);

        $requests = $this->requests();
        self::assertSame('interactions/int-1', $operation['operationName']);
        self::assertSame(['POST', 'https://generativelanguage.googleapis.com/v1beta/interactions'], [$requests[0]['method'], $requests[0]['url']]);
        self::assertSame(['DELETE', 'https://generativelanguage.googleapis.com/v1beta/interactions/int-1'], [$requests[1]['method'], $requests[1]['url']]);
    }

    public function testStopCancelsTheRenderBeforeTheFirstWait(): void
    {
        $video = $this->video($this->api([self::json(['name' => 'operations/op-2']), self::json([])]));

        try {
            $video->generateVideo('A lake', ['cancel_check' => static fn (): bool => true]);
            self::fail('A stopped render must end in a cancellation.');
        } catch (ProviderCancelledException) {
        }

        self::assertSame(['POST', 'https://generativelanguage.googleapis.com/v1beta/operations/op-2:cancel'], [$this->requests()[1]['method'], $this->requests()[1]['url']]);
    }

    private function video(GoogleApiClient $api): GoogleVideoGeneration
    {
        return new GoogleVideoGeneration($api, new NullLogger(), new GoogleOmniVideo($api, new NullLogger()));
    }
}
