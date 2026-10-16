<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ProviderTranscript;
use PHPUnit\Framework\TestCase;

final class ProviderTranscriptTest extends TestCase
{
    public function testRedactsSecretsAndKeepsRoleOrder(): void
    {
        $transcript = ProviderTranscript::fromMessages([
            ['role' => 'system', 'content' => 'Follow the rules. Bearer abc.def'],
            ['role' => 'user', 'content' => 'my key is sk-test-secret'],
            ['role' => 'assistant', 'content' => 'Noted.'],
        ])->redact();

        self::assertSame([
            ['role' => 'instructions', 'text' => 'Follow the rules. …'],
            ['role' => 'you', 'text' => 'my key is …'],
            ['role' => 'assistant', 'text' => 'Noted.'],
        ], $transcript->toArray());
        self::assertFalse($transcript->isTruncated());
    }

    public function testCapsTheWholeTranscript(): void
    {
        $transcript = ProviderTranscript::fromMessages([
            ['role' => 'user', 'content' => str_repeat('a', 100)],
            ['role' => 'assistant', 'content' => str_repeat('b', 100)],
        ])->cap(120);

        $texts = array_map(static fn (array $row): string => $row['text'], $transcript->toArray());
        self::assertSame(120, array_sum(array_map(mb_strlen(...), $texts)));
        self::assertTrue($transcript->isTruncated());
        self::assertSame('you', $transcript->toArray()[0]['role']);
    }
}
