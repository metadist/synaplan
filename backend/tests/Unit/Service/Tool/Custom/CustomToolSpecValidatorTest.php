<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Custom;

use App\Service\Tool\Custom\CustomToolSpecValidator;
use App\Service\Tool\Custom\InvalidToolTemplateException;
use App\Service\Tool\Custom\TemplateRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomToolSpecValidatorTest extends TestCase
{
    public function testAcceptsTemplatesInPathAndQueryOnly(): void
    {
        $spec = $this->validator()->validate([
            'method' => 'get',
            'url' => 'https://api.example.com/tickets/{{input.id}}?verbose={{input.verbose}}',
            'query' => ['q' => '{{input.q}}'],
            'headers' => ['X-Auth' => '{{credential.header}}'],
        ], 'read', 'lookup_ticket');
        $this->assertSame('GET', $spec['method']);
    }

    /**
     * @return list<list<string>>
     */
    public static function rejectedUrls(): array
    {
        return [
            ['{{input.url}}'],
            ['https://{{input.host}}/x'],
            ['https://api.{{input.tenant}}.example.com/x'],
            ['ftp://example.com/x'],
            ['https://user:pw@example.com/x'],
            ['example.com/x'],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsUrlsWithoutLiteralOrigin(string $url): void
    {
        $this->expectException(InvalidToolTemplateException::class);
        $this->validator()->validate(['method' => 'GET', 'url' => $url], 'read', 'lookup');
    }

    public function testRejectsUnknownSpecKeysMethodsAndNames(): void
    {
        $validator = $this->validator();
        $ok = ['method' => 'GET', 'url' => 'https://example.com/'];
        $this->expectException(InvalidToolTemplateException::class);
        $validator->validate($ok + ['proxy' => 'x'], 'read', 'lookup');
    }

    public function testRejectsNonMapQuery(): void
    {
        $this->expectException(InvalidToolTemplateException::class);
        $this->validator()->validate(['method' => 'GET', 'url' => 'https://example.com/', 'query' => 'a=b'], 'read', 'lookup');
    }

    private function validator(): CustomToolSpecValidator
    {
        return new CustomToolSpecValidator(new TemplateRenderer());
    }
}
