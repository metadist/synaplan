<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Custom;

use App\Service\Tool\Custom\InvalidToolTemplateException;
use App\Service\Tool\Custom\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    public function testRendersAllowedTokens(): void
    {
        $renderer = new TemplateRenderer();
        $out = $renderer->render(
            'https://api.example.com/{{input.id}}?q={{input.q}}',
            ['id' => '42', 'q' => 'hello'],
        );
        $this->assertSame('https://api.example.com/42?q=hello', $out);
        $this->assertSame(
            'Bearer secret',
            $renderer->render('{{credential.header}}', [], [], 'Bearer secret'),
        );
    }

    public function testRejectsExpressionsAndUnknownTokens(): void
    {
        $renderer = new TemplateRenderer();
        $this->expectException(InvalidToolTemplateException::class);
        $renderer->assertSubset('{{input.id | upper}}');
    }

    public function testRejectsNestedBraces(): void
    {
        $renderer = new TemplateRenderer();
        $this->expectException(InvalidToolTemplateException::class);
        $renderer->assertSubset('{{input.{{nested}}}}');
    }
}
