<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use PHPUnit\Framework\TestCase;

/**
 * C4: production callers must not import Tika or Brave directly.
 * FileProcessor stays in the same namespace as TikaClient (no use statement).
 * MessagePreProcessor is the remaining direct Tika caller until a later sprint.
 */
final class PlugBoundaryTest extends TestCase
{
    private const TIKA_USE = 'use App\\Service\\File\\TikaClient';
    private const BRAVE_USE = 'use App\\Service\\Search\\BraveSearchService';

    public function testTikaClientIsNotImportedOutsideThePlugBoundary(): void
    {
        $violations = $this->scan(self::TIKA_USE, [
            '/Plug/',
            '/Service/File/TikaClient.php',
            '/Service/Message/MessagePreProcessor.php',
        ]);

        $this->assertSame([], $violations, "TikaClient imports outside the plug boundary:\n".implode("\n", $violations));
    }

    public function testDoclingClientIsNotImportedOutsideThePlugBoundary(): void
    {
        $violations = $this->scan('use App\\Plug\\Extraction\\Docling\\DoclingClient', [
            '/Plug/',
        ]);

        $this->assertSame([], $violations, "DoclingClient imports outside the plug boundary:\n".implode("\n", $violations));
    }

    public function testBraveSearchServiceIsNotImportedOutsideThePlugBoundary(): void
    {
        $violations = $this->scan(self::BRAVE_USE, [
            '/Plug/',
            '/Service/Search/BraveSearchService.php',
        ]);

        $this->assertSame([], $violations, "BraveSearchService imports outside the plug boundary:\n".implode("\n", $violations));
    }

    /**
     * @return list<string>
     */
    public static function webSearchClientUseStatements(): array
    {
        return [
            'use App\\Plug\\WebSearch\\Client\\SearxngClient',
            'use App\\Plug\\WebSearch\\Client\\TavilyClient',
            'use App\\Plug\\WebSearch\\Client\\ExaClient',
            'use App\\Plug\\WebSearch\\Client\\FirecrawlClient',
            'use App\\Plug\\WebSearch\\Client\\PerplexitySearchClient',
        ];
    }

    public function testWebSearchClientsAreNotImportedOutsideThePlugBoundary(): void
    {
        foreach (self::webSearchClientUseStatements() as $needle) {
            $violations = $this->scan($needle, ['/Plug/']);
            $this->assertSame([], $violations, $needle." imports outside the plug boundary:\n".implode("\n", $violations));
        }
    }

    public function testRerankHttpClientIsNotImportedOutsideThePlugBoundary(): void
    {
        $violations = $this->scan('use App\\Plug\\Rerank\\Client\\HttpRerankClient', ['/Plug/']);
        $this->assertSame([], $violations, "HttpRerankClient imports outside the plug boundary:\n".implode("\n", $violations));
    }

    /**
     * A reference plugin lives entirely under plugins/; its identifiers must
     * never leak into core (the whole point of the plug-adapter mechanism).
     */
    public function testReferencePluginIdentifiersStayOutOfCore(): void
    {
        foreach (['serper', 'Serper'] as $needle) {
            $violations = $this->scan($needle, []);
            $this->assertSame([], $violations, "'".$needle."' must not appear in backend/src:\n".implode("\n", $violations));
        }
    }

    /**
     * @param list<string> $allowedPathFragments
     *
     * @return list<string>
     */
    private function scan(string $needle, array $allowedPathFragments): array
    {
        $src = dirname(__DIR__, 3).'/src';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $path = $file->getPathname();
            $allowed = false;
            foreach ($allowedPathFragments as $fragment) {
                if (str_contains($path, $fragment)) {
                    $allowed = true;
                    break;
                }
            }
            if ($allowed) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (str_contains($contents, $needle)) {
                $violations[] = substr($path, strlen($src));
            }
        }

        return $violations;
    }
}
