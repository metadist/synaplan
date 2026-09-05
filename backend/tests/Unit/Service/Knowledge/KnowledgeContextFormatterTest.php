<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Knowledge;

use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\SelfAware\Docs\PlatformDocsHit;
use App\Service\SelfAware\Docs\PlatformDocsHits;
use PHPUnit\Framework\TestCase;

final class KnowledgeContextFormatterTest extends TestCase
{
    public function testFormatRagAndMemoriesAndClamp(): void
    {
        $fmt = new KnowledgeContextFormatter();

        $rag = $fmt->formatRagContext([
            ['id' => 2, 'chunk_text' => ' second '],
            ['id' => 1, 'chunk_text' => ' first '],
        ]);
        $this->assertStringContainsString('[Source 1] first', $rag);
        $this->assertStringContainsString('[Source 2] second', $rag);

        $shared = $fmt->formatRagContext([
            [
                'chunk_id' => 1,
                'chunk_text' => 'playbook',
                'file_name' => 'q3.pdf',
                'shared' => true,
                'owner_name' => 'Ada',
            ],
        ]);
        $this->assertStringContainsString('q3.pdf (Ada)', $shared);

        $mem = $fmt->formatMemoriesContext([
            ['id' => 9, 'key' => 'k', 'value' => 'v'],
        ]);
        $this->assertStringContainsString('[ID: 9] k: v', $mem);
        $this->assertStringContainsString('[Memory:ID]', $mem);

        $clamped = $fmt->combineAndClamp('ABCDEFGHIJ', '', 5);
        $this->assertSame("ABCDE\n…", $clamped);
    }

    public function testFormatPlatformDocsContextRendersDocPillsAndRules(): void
    {
        $fmt = new KnowledgeContextFormatter();
        $block = $fmt->formatPlatformDocsContext(new PlatformDocsHits([
            new PlatformDocsHit(
                'channels',
                'Channels: WhatsApp & Email',
                'https://docs.synaplan.com/channels',
                'Using',
                'Connect WhatsApp via the Meta Business API.',
                0.8,
            ),
            new PlatformDocsHit(
                'widget',
                'Widget Integration',
                'https://docs.synaplan.com/widget',
                'Using',
                'Embed the chat widget with a script tag.',
                0.7,
            ),
        ]));

        $this->assertStringContainsString('[Doc:channels] Channels: WhatsApp & Email', $block);
        $this->assertStringContainsString('[Doc:widget] Widget Integration', $block);
        $this->assertStringContainsString('ONE slug per bracket', $block);
        $this->assertStringContainsString('Never invent slugs', $block);
        $this->assertSame('', (new KnowledgeContextFormatter())->formatPlatformDocsContext(new PlatformDocsHits([])));
    }

    public function testFormatDigestContextEmptyInputYieldsEmptyString(): void
    {
        $this->assertSame('', (new KnowledgeContextFormatter())->formatDigestContext([]));
    }

    public function testFormatDigestContextRendersLinesExcerptAndReferenceRules(): void
    {
        $fmt = new KnowledgeContextFormatter();

        $block = $fmt->formatDigestContext([
            $this->digest(1234, 'office rent letter about the increase', excerpt: "Dear Sir,\nthe rent rises."),
            $this->digest(5678, 'insurance policy renewal', sourceDate: 0, channel: ''),
        ]);

        $this->assertStringContainsString('## Older conversations', $block);
        $this->assertStringContainsString('[Msg: 1234 | 2026-05-01 | web] office rent letter about the increase', $block);
        // Multi-line excerpt is blockquoted under its digest line.
        $this->assertStringContainsString("> Dear Sir,\n> the rent rises.", $block);
        // Missing source date / channel fall back to readable placeholders.
        $this->assertStringContainsString('[Msg: 5678 | unknown date | chat] insurance policy renewal', $block);
        $this->assertStringContainsString('[Message:ID]', $block);
        $this->assertStringContainsString('Never invent IDs', $block);
    }

    public function testFormatDigestContextDropsExcerptsBeforeDigestLines(): void
    {
        $fmt = new KnowledgeContextFormatter();

        $digests = [
            $this->digest(1, 'first title', excerpt: str_repeat('x', 500)),
            $this->digest(2, 'second title', excerpt: str_repeat('y', 500)),
        ];

        // Budget fits both lines + rules but only one excerpt.
        $block = $fmt->formatDigestContext($digests, 1000);

        $this->assertStringContainsString('first title', $block);
        $this->assertStringContainsString('second title', $block);
        $this->assertStringContainsString(str_repeat('x', 500), $block);
        $this->assertStringNotContainsString(str_repeat('y', 500), $block);
        $this->assertLessThanOrEqual(1000, mb_strlen($block));
    }

    public function testFormatDigestContextReturnsEmptyWhenNoLineFits(): void
    {
        $fmt = new KnowledgeContextFormatter();

        $block = $fmt->formatDigestContext([$this->digest(1, str_repeat('t', 400))], 300);

        $this->assertSame('', $block);
    }

    /**
     * @return array{message_id: int, chat_id: int, title: string, channel: string, source_date: int, excerpt: string|null}
     */
    private function digest(
        int $messageId,
        string $title,
        ?string $excerpt = null,
        int $sourceDate = 1_777_636_800, // 2026-05-01 12:00 UTC — date() safe for any server TZ
        string $channel = 'web',
    ): array {
        return [
            'message_id' => $messageId,
            'chat_id' => 10,
            'title' => $title,
            'channel' => $channel,
            'source_date' => $sourceDate,
            'excerpt' => $excerpt,
        ];
    }
}
