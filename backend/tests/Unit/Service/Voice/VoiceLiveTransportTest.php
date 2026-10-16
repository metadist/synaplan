<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Voice;

use App\Service\Voice\VoiceLiveTransport;
use PHPUnit\Framework\TestCase;

final class VoiceLiveTransportTest extends TestCase
{
    public function testDirectAudioStaysOffUntilAnAdminEnablesOpenAi(): void
    {
        self::assertSame('relay', VoiceLiveTransport::choose('openai', 'gpt-live-1', false));
        self::assertSame('webrtc', VoiceLiveTransport::choose('openai', 'gpt-live-1', true));
        self::assertSame('webrtc', VoiceLiveTransport::choose('OpenAI', 'gpt-realtime-2.1', true));
        self::assertSame('relay', VoiceLiveTransport::choose('google', 'gemini-3.8-live', true));
        self::assertSame('relay', VoiceLiveTransport::choose('xai', 'grok-voice-3.0', true));
        self::assertSame('relay', VoiceLiveTransport::choose('openai', 'gpt-live-1', true, 'carplay'));
        self::assertSame('relay', VoiceLiveTransport::choose('compatible', 'qwen3-omni', true));
    }
}
