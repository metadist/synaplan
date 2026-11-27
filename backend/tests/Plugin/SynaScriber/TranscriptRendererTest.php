<?php

declare(strict_types=1);

namespace App\Tests\Plugin\SynaScriber;

use PHPUnit\Framework\TestCase;
use Plugin\SynaScriber\Service\TranscriptRenderer;

final class TranscriptRendererTest extends TestCase
{
    public function testAUuidSpeakerKeepsTheRosterName(): void
    {
        $uuid = 'ed4c78a1-b1ec-49cf-9c9c-ef43b11cfd10';
        $markdown = (new TranscriptRenderer())->render([
            'language' => 'en',
            'room' => 'weekly',
            'starterName' => 'Ada',
            'startedAt' => 1_700_000_000,
            'stoppedAt' => 1_700_000_300,
            'roster' => [$uuid => 'Ada'],
            'attendees' => [],
        ], [
            ['at' => 1_700_000_000_000, 'speaker' => $uuid, 'text' => 'Hello from the valley'],
        ], new \DateTimeZone('UTC'));

        self::assertStringContainsString('**22:13 · Ada:** Hello from the valley', $markdown);
        self::assertStringNotContainsString('Participant 1', $markdown);
    }

    public function testAJitsiTagStillUsesThePartBeforeTheFirstHyphen(): void
    {
        $markdown = (new TranscriptRenderer())->render([
            'language' => 'en',
            'room' => 'weekly',
            'starterName' => 'Ada',
            'startedAt' => 1_700_000_000,
            'stoppedAt' => 1_700_000_300,
            'roster' => ['ep1' => 'Ada'],
            'attendees' => [],
        ], [
            ['at' => 1_700_000_000_000, 'speaker' => 'ep1-99', 'text' => 'Hello'],
        ], new \DateTimeZone('UTC'));

        self::assertStringContainsString('**22:13 · Ada:** Hello', $markdown);
        self::assertStringNotContainsString('Participant 1', $markdown);
    }
}
