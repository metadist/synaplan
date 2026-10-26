<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Usage;

use App\Service\Usage\UsageKind;
use PHPUnit\Framework\TestCase;

final class UsageKindTest extends TestCase
{
    public function testRawActionsMapToBoardKinds(): void
    {
        self::assertSame(UsageKind::CHAT, UsageKind::fromAction('MESSAGES'));
        self::assertSame(UsageKind::VIDEO, UsageKind::fromAction('VIDEOS'));
        self::assertSame(UsageKind::VIDEO, UsageKind::fromAction('video_generation'));
        self::assertSame(UsageKind::IMAGE, UsageKind::fromAction('IMAGES'));
        self::assertSame(UsageKind::IMAGE, UsageKind::fromAction('image_generation'));
        self::assertSame(UsageKind::AUDIO, UsageKind::fromAction('AUDIOS'));
        self::assertSame(UsageKind::VOICE, UsageKind::fromAction('VOICE_LIVE'));
        self::assertSame(UsageKind::TRANSCRIPTION, UsageKind::fromAction('TRANSCRIPTION'));
        self::assertSame(UsageKind::TRANSCRIPTION, UsageKind::fromAction('speech_to_text'));
        self::assertSame(UsageKind::EMBEDDING, UsageKind::fromAction('EMBEDDINGS'));
        self::assertSame(UsageKind::EMBEDDING, UsageKind::fromAction('embedding'));
        self::assertSame(UsageKind::AGENT, UsageKind::fromAction('TOOLS'));
        self::assertSame(UsageKind::AGENT, UsageKind::fromAction('PLANNING'));
        self::assertSame(UsageKind::COMPUTE, UsageKind::fromAction('COMPUTE_RUNS'));
        self::assertSame(UsageKind::FILE, UsageKind::fromAction('DOC_SUMMARY'));
        self::assertSame(UsageKind::INTERNAL, UsageKind::fromAction('SORTING'));
        self::assertSame(UsageKind::OTHER, UsageKind::fromAction('SOMETHING_NEW'));
    }

    public function testAnAgentFlagWinsOverTheRawAction(): void
    {
        self::assertSame(UsageKind::AGENT, UsageKind::fromAction('MESSAGES', true));
        self::assertTrue(UsageKind::hasAgent(['agentId' => 7]));
        self::assertTrue(UsageKind::hasAgent(['agent_id' => '7']));
        self::assertTrue(UsageKind::hasAgent(['source' => 'saved_task']));
        self::assertFalse(UsageKind::hasAgent(['source' => 'WEB']));
        self::assertFalse(UsageKind::hasAgent(['agentId' => 0]));
        self::assertFalse(UsageKind::hasAgent([]));
    }

    public function testChannelAndIdsComeFromMetadata(): void
    {
        self::assertSame('WHATSAPP', UsageKind::channel(['source' => 'whatsapp']));
        self::assertSame('WEB', UsageKind::channel([]));
        self::assertSame('WEB', UsageKind::channel(['source' => '']));
        self::assertSame(12, UsageKind::chatId(['chat_id' => '12']));
        self::assertNull(UsageKind::chatId(['chat_id' => 0]));
        self::assertSame(3, UsageKind::agentId(['agentId' => 3]));
        self::assertNull(UsageKind::agentId([]));
    }
}
