<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\GeneratedMediaTextRenderer;
use App\Service\Message\PlaceholderReply;
use PHPUnit\Framework\TestCase;

final class PlaceholderReplyTest extends TestCase
{
    public function testEmptyAndMediaTokensArePlaceholders(): void
    {
        self::assertTrue(PlaceholderReply::isPlaceholder(''));
        self::assertTrue(PlaceholderReply::isPlaceholder('   '));
        self::assertTrue(PlaceholderReply::isPlaceholder(GeneratedMediaTextRenderer::MARKER_IMAGE_GENERATING));
        self::assertTrue(PlaceholderReply::isPlaceholder(GeneratedMediaTextRenderer::MARKER_IMAGE));
        self::assertTrue(PlaceholderReply::isPlaceholder(GeneratedMediaTextRenderer::MARKER_VIDEO_GENERATING));
    }

    public function testEveryFallbackLocaleIsAPlaceholder(): void
    {
        foreach (PlaceholderReply::FALLBACK_TEXT as $sentence) {
            self::assertTrue(PlaceholderReply::isPlaceholder($sentence));
        }
    }

    public function testModelProseAndFolderNotesAreNotPlaceholders(): void
    {
        self::assertFalse(PlaceholderReply::isPlaceholder('Here is your parrot.'));
        self::assertFalse(PlaceholderReply::isPlaceholder("__IMAGE_GENERATED__\n\nSaved to Projects/Art."));
        self::assertFalse(PlaceholderReply::isPlaceholder(GeneratedMediaTextRenderer::MARKER_FILE_PREFIX.'notes.pdf'));
    }
}
