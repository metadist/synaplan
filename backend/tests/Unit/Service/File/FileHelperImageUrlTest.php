<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the image-URL-in-text detection that lets a "make a video from
 * <image-url>" request route to image-to-video instead of text-to-video.
 */
class FileHelperImageUrlTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('imageUrlCases')]
    public function testExtractImageUrls(string $text, array $expected): void
    {
        $this->assertSame($expected, FileHelper::extractImageUrls($text));
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function imageUrlCases(): iterable
    {
        yield 'pexels jpeg with surrounding words' => [
            'Can you create a video from this https://images.pexels.com/photos/33926926/pexels-photo-33926926.jpeg image, where the sun goes over the sea',
            ['https://images.pexels.com/photos/33926926/pexels-photo-33926926.jpeg'],
        ];

        yield 'trailing sentence punctuation is trimmed' => [
            'Animate https://example.com/cat.png.',
            ['https://example.com/cat.png'],
        ];

        yield 'query string is tolerated' => [
            'use https://cdn.example.com/a/b.jpg?auto=compress&cs=tinysrgb please',
            ['https://cdn.example.com/a/b.jpg?auto=compress&cs=tinysrgb'],
        ];

        yield 'non-image url is ignored' => [
            'check https://example.com/article and https://youtu.be/abc123',
            [],
        ];

        yield 'plain text with no url' => [
            'make a video of a sunset over the ocean',
            [],
        ];

        yield 'duplicates removed, order preserved' => [
            'https://a.com/1.png then https://b.com/2.webp then https://a.com/1.png',
            ['https://a.com/1.png', 'https://b.com/2.webp'],
        ];

        yield 'multiple extensions' => [
            'http://x/y.gif and https://x/z.jpeg',
            ['http://x/y.gif', 'https://x/z.jpeg'],
        ];

        yield 'empty string' => [
            '',
            [],
        ];
    }
}
