<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\Google;

use App\AI\Provider\Google\GoogleOmniVideo;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GoogleOmniVideoTest extends TestCase
{
    use GoogleApiDouble;

    public function testAClipIsAPrefixedInteractionWithItsDurationClamped(): void
    {
        $omni = new GoogleOmniVideo($this->api([self::json(['id' => 'int-9'])]), new NullLogger());

        $operation = $omni->start('A cat', ['duration' => 1], 'gemini-omni-flash', self::KEY);

        self::assertSame(['operationName' => 'interactions/int-9', 'model' => 'gemini-omni-flash', 'duration' => 3, 'resolution' => '720p'], $operation);
        self::assertSame(['type' => 'video', 'aspect_ratio' => '16:9', 'duration' => '3s', 'resolution' => '720p'], $this->requests()[0]['body']['response_format']);
    }

    public function testThePollPrefersTheVideoOfTheModelOutput(): void
    {
        $omni = new GoogleOmniVideo($this->api([self::json(['status' => 'completed', 'steps' => [
            ['type' => 'thought', 'content' => [['type' => 'video', 'uri' => 'https://example.test/draft.mp4']]],
            ['type' => 'model_output', 'content' => [['type' => 'video', 'uri' => 'https://example.test/final.mp4']]],
        ]])]), new NullLogger());

        self::assertSame(['done' => true, 'videoUri' => 'https://example.test/final.mp4', 'error' => null], $omni->pollOnce('interactions/int-9', self::KEY));
        self::assertStringEndsWith('/interactions/int-9', $this->requests()[0]['url']);
    }

    public function testItOwnsOnlyItsModelsAndPrefixedOperations(): void
    {
        $omni = new GoogleOmniVideo($this->api([]), new NullLogger());

        self::assertSame([true, false], [$omni->handlesModel('Gemini-Omni-Flash'), $omni->handlesModel('veo-3.1-generate-preview')]);
        self::assertSame([true, false], [$omni->ownsOperation('interactions/int-9'), $omni->ownsOperation('operations/op-1')]);
        self::assertSame(['int-9', 'operations/op-1'], [$omni->interactionId('interactions/int-9'), $omni->interactionId('operations/op-1')]);
    }
}
