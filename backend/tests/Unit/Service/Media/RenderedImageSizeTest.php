<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\RenderedImageSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RenderedImageSizeTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function sizes(): iterable
    {
        yield 'square' => ['1024x1024', '1024x1024'];
        yield 'gpt-image-2.5 square' => ['1254x1254', '1024x1024'];
        yield 'landscape 3:2' => ['1536x1024', '1536x1024'];
        yield 'landscape 4:3 from GPT Image 2.5' => ['1448x1086', '1536x1024'];
        yield 'portrait 2:3' => ['1024x1536', '1024x1536'];
        yield 'portrait 3:4' => ['1086x1448', '1024x1536'];
        yield 'almost square' => ['1056x1024', '1024x1024'];
        yield 'upper-case separator' => ['1536X1024', '1536x1024'];
        yield 'auto' => ['auto', null];
        yield 'empty' => ['', null];
        yield 'missing' => [null, null];
        yield 'zero height' => ['1024x0', null];
    }

    #[DataProvider('sizes')]
    public function testItMapsTheRenderedSizeOntoThePriceKey(?string $rendered, ?string $expected): void
    {
        self::assertSame($expected, RenderedImageSize::billingKey($rendered));
    }
}
