<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Tools\AnthropicServerTools;
use App\AI\Messages\Tools\WebSearchTool;
use App\Service\Search\BraveSearchService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebSearchToolTest extends TestCase
{
    public function testDeclarationIsAnExecutableToolWithAQuerySchema(): void
    {
        $declaration = $this->tool($this->createMock(BraveSearchService::class))->declaration();

        self::assertSame(WebSearchTool::NAME, $declaration['name']);
        self::assertSame('object', $declaration['input_schema']['type']);
        self::assertArrayHasKey('query', $declaration['input_schema']['properties']);
        self::assertSame(['query'], $declaration['input_schema']['required']);
    }

    public function testExecuteReturnsFormattedResults(): void
    {
        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(true);
        $brave->expects($this->once())
            ->method('search')
            ->with('ecb interest rate', ['count' => 3, 'search_lang' => 'de', 'country' => 'de', 'freshness' => 'pw'])
            ->willReturn(['query' => 'ecb interest rate', 'results' => [['title' => 'ECB']]]);
        $brave->method('formatResultsForAI')->willReturn('Web Search Results …');

        $result = $this->tool($brave)->execute([
            'query' => 'ecb interest rate',
            'max_results' => 3,
            'language' => 'de',
            'freshness' => 'pw',
        ]);

        self::assertFalse($result['isError']);
        self::assertSame('Web Search Results …', $result['text']);
        self::assertSame(1, $result['resultCount']);
    }

    public function testMaxResultsIsClamped(): void
    {
        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(true);
        $brave->expects($this->once())
            ->method('search')
            ->with('anything', ['count' => 10])
            ->willReturn(['query' => 'anything', 'results' => []]);
        $brave->method('formatResultsForAI')->willReturn('no results');

        $this->tool($brave)->execute(['query' => 'anything', 'max_results' => 500]);
    }

    public function testSearchFailureBecomesAnErrorResultInsteadOfAnException(): void
    {
        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(true);
        $brave->method('search')->willThrowException(new \RuntimeException('Brave Search API returned status code: 429'));

        $result = $this->tool($brave)->execute(['query' => 'anything']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('429', $result['text']);
    }

    public function testUnconfiguredProviderIsReportedToTheModel(): void
    {
        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(false);

        $result = $this->tool($brave)->execute(['query' => 'anything']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('not configured', $result['text']);
    }

    public function testEmptyQueryIsRejected(): void
    {
        $result = $this->tool($this->createMock(BraveSearchService::class))->execute(['query' => '   ']);

        self::assertTrue($result['isError']);
    }

    /**
     * @return array<string, array{array<string, mixed>, bool, bool}>
     */
    public static function toolDeclarations(): array
    {
        return [
            'anthropic web search server tool' => [['type' => 'web_search_20250305', 'name' => 'web_search'], true, true],
            'later dated revision' => [['type' => 'web_search_20260209', 'name' => 'web_search'], true, true],
            'code execution server tool' => [['type' => 'code_execution_20250825', 'name' => 'code_execution'], true, false],
            'plain client tool' => [['name' => 'Bash', 'input_schema' => ['type' => 'object']], false, false],
            'explicitly typed client tool' => [['type' => 'custom', 'name' => 'Bash', 'input_schema' => ['type' => 'object']], false, false],
            'typed tool that still carries a schema' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'input_schema' => ['type' => 'object']], false, false],
        ];
    }

    /**
     * @param array<string, mixed> $tool
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toolDeclarations')]
    public function testServerToolClassification(array $tool, bool $isServerTool, bool $isWebSearch): void
    {
        self::assertSame($isServerTool, AnthropicServerTools::isServerToolDeclaration($tool));
        self::assertSame($isWebSearch, AnthropicServerTools::isWebSearch($tool));
    }

    private function tool(BraveSearchService $brave): WebSearchTool
    {
        return new WebSearchTool($brave, new NullLogger());
    }
}
