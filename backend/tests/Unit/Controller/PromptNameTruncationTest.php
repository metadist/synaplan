<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\PromptController;
use PHPUnit\Framework\TestCase;

/**
 * Regression cover for #1571: byte-level truncation of the prompt short
 * description split a UTF-8 sequence, producing a name that the Symfony JSON
 * encoder rejects. A single such row made GET /api/v1/prompts return 500 for
 * the whole list.
 */
final class PromptNameTruncationTest extends TestCase
{
    private function formatPromptName(string $topic, string $shortDescription, bool $isDefault = true): string
    {
        $method = new \ReflectionMethod(PromptController::class, 'formatPromptName');

        return $method->invoke(
            (new \ReflectionClass(PromptController::class))->newInstanceWithoutConstructor(),
            $topic,
            $shortDescription,
            $isDefault,
        );
    }

    /**
     * The exact payload from the report: `ü` starts at byte offset 56, so a
     * byte-wise substr(…, 0, 57) keeps its lead byte and drops the continuation.
     */
    public function testMultibyteCharacterOnByte57BoundaryStaysValidUtf8(): void
    {
        $description = 'user asks about the daily news for germany and NRW and münster';
        self::assertSame(63, \strlen($description), 'guard: the fixture must cross the 60-byte threshold');
        self::assertSame(62, mb_strlen($description), 'guard: the fixture must stay under the 60-character threshold in bytes only');

        $name = $this->formatPromptName('qwer', $description, false);

        self::assertTrue(mb_check_encoding($name, 'UTF-8'));
        self::assertNotFalse(json_encode(['name' => $name]));
    }

    /**
     * Truncation is now character-based, so a description made entirely of
     * multibyte characters must survive it intact as well.
     */
    public function testAllMultibyteDescriptionIsTruncatedByCharacters(): void
    {
        $description = str_repeat('ä', 80);

        $name = $this->formatPromptName('umlauts', $description, false);

        self::assertTrue(mb_check_encoding($name, 'UTF-8'));
        self::assertNotFalse(json_encode(['name' => $name]));
        self::assertSame('(custom) umlauts - '.str_repeat('ä', 57).'...', $name);
    }

    public function testShortDescriptionIsNotTruncated(): void
    {
        self::assertSame(
            '(default) general - Answers general questions',
            $this->formatPromptName('general', 'Answers general questions'),
        );
    }
}
