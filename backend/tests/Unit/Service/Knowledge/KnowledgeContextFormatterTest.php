<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Knowledge;

use App\Service\Knowledge\KnowledgeContextFormatter;
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

        $mem = $fmt->formatMemoriesContext([
            ['id' => 9, 'key' => 'k', 'value' => 'v'],
        ]);
        $this->assertStringContainsString('[ID: 9] k: v', $mem);
        $this->assertStringContainsString('[Memory:ID]', $mem);

        $clamped = $fmt->combineAndClamp('ABCDEFGHIJ', '', 5);
        $this->assertSame("ABCDE\n…", $clamped);
    }
}
