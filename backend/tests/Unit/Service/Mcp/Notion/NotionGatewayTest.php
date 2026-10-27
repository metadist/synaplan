<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Service\Mcp\Notion\NotionGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotionGatewayTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function pageInputs(): iterable
    {
        yield 'dashed id' => ['39797AD6-0EB3-806D-B59E-EF7653838664', '39797ad6-0eb3-806d-b59e-ef7653838664'];
        yield 'page link with title' => ['https://www.notion.so/acme/Roadmap-39797ad60eb3806db59eef7653838664', '39797ad6-0eb3-806d-b59e-ef7653838664'];
        yield 'app.notion.com link with query' => ['https://app.notion.com/p/39797ad60eb3806db59eef7653838664?pvs=204', '39797ad6-0eb3-806d-b59e-ef7653838664'];
        yield 'public site' => ['https://acme.notion.site/Docs-39797ad60eb3806db59eef7653838664', '39797ad6-0eb3-806d-b59e-ef7653838664'];
        yield 'other host' => ['https://evil.example/39797ad60eb3806db59eef7653838664', null];
        yield 'look-alike host' => ['https://notion.so.evil.example/39797ad60eb3806db59eef7653838664', null];
        yield 'no id' => ['https://www.notion.so/acme/Roadmap', null];
        yield 'empty' => ['  ', null];
    }

    #[DataProvider('pageInputs')]
    public function testPageIdAcceptsNotionLinksOnly(string $input, ?string $expected): void
    {
        self::assertSame($expected, NotionGateway::pageId($input));
    }

    public function testPageUrlPointsAtNotion(): void
    {
        self::assertSame('https://www.notion.so/39797ad60eb3806db59eef7653838664', NotionGateway::pageUrl('39797ad6-0eb3-806d-b59e-ef7653838664'));
    }
}
