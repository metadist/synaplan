<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Service\ModelListPageCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelListPageCursorTest extends TestCase
{
    #[DataProvider('provideBodies')]
    public function testNextUrl(string $baseUrl, array $body, ?string $expected): void
    {
        $this->assertSame($expected, ModelListPageCursor::nextUrl($baseUrl, $body));
    }

    /**
     * @return iterable<string, array{string, array<mixed>, ?string}>
     */
    public static function provideBodies(): iterable
    {
        yield 'google first page' => [
            'https://generativelanguage.googleapis.com/v1beta/models',
            ['nextPageToken' => 'tok/with spaces'],
            'https://generativelanguage.googleapis.com/v1beta/models?pageToken=tok%2Fwith%20spaces',
        ];
        yield 'google with existing query' => [
            'https://example.com/models?pageSize=50',
            ['nextPageToken' => 'abc'],
            'https://example.com/models?pageSize=50&pageToken=abc',
        ];
        yield 'google exhausted' => [
            'https://example.com/models',
            ['models' => []],
            null,
        ];
        yield 'google empty token ignored' => [
            'https://example.com/models',
            ['nextPageToken' => ''],
            null,
        ];
        yield 'anthropic has more' => [
            'https://api.anthropic.com/v1/models',
            ['has_more' => true, 'last_id' => 'model-xyz'],
            'https://api.anthropic.com/v1/models?after_id=model-xyz',
        ];
        yield 'anthropic has more with query' => [
            'https://api.anthropic.com/v1/models?limit=20',
            ['has_more' => true, 'last_id' => 'id/with spaces'],
            'https://api.anthropic.com/v1/models?limit=20&after_id=id%2Fwith%20spaces',
        ];
        yield 'anthropic done' => [
            'https://api.anthropic.com/v1/models',
            ['has_more' => false, 'last_id' => 'model-xyz'],
            null,
        ];
        yield 'anthropic has_more without last_id' => [
            'https://api.anthropic.com/v1/models',
            ['has_more' => true],
            null,
        ];
        yield 'google token preferred over anthropic' => [
            'https://example.com/models',
            ['nextPageToken' => 'g', 'has_more' => true, 'last_id' => 'a'],
            'https://example.com/models?pageToken=g',
        ];
    }
}
