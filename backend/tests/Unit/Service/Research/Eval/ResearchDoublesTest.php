<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Eval;

use App\Plug\WebSearch\Adapter\TestWebSearchAdapter;
use App\Plug\WebSearch\WebSearchQuery;
use App\Service\Research\Run\Source\FixtureSourceReader;
use PHPUnit\Framework\TestCase;

final class ResearchDoublesTest extends TestCase
{
    public function testAKnownQueryReturnsTheFixtureAndAnUnknownQueryIsEmpty(): void
    {
        $dir = sys_get_temp_dir().'/research-search-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/act.json', json_encode([
            'query' => 'EU Artificial Intelligence Act',
            'results' => [[
                'title' => 'Act',
                'url' => 'https://fixture.synaplan.test/ai-act.html',
                'description' => 'Article 53',
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $adapter = new TestWebSearchAdapter($dir);
            $found = $adapter->search(new WebSearchQuery('eu artificial intelligence act'));
            $missing = $adapter->search(new WebSearchQuery('something else'));

            $this->assertCount(1, $found->results);
            $this->assertSame('https://fixture.synaplan.test/ai-act.html', $found->results[0]['url']);
            $this->assertSame([], $missing->results);
        } finally {
            unlink($dir.'/act.json');
            rmdir($dir);
        }
    }

    public function testTheFixtureReaderServesItsHostAndRefusesAnyOther(): void
    {
        $dir = sys_get_temp_dir().'/research-pages-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/ai-act.html', '<p>Article 53</p>');
        $reader = new FixtureSourceReader($dir);

        try {
            $page = $reader->read('https://fixture.synaplan.test/ai-act.html');
            $other = $reader->read('https://example.com/ai-act.html');

            $this->assertFalse($page->blocked);
            $this->assertStringContainsString('Article 53', $page->text);
            $this->assertTrue($other->blocked);
            $this->assertSame(FixtureSourceReader::REFUSED, $other->reason);
            $this->assertSame('', $other->text);
        } finally {
            unlink($dir.'/ai-act.html');
            rmdir($dir);
        }
    }

    public function testTheDoublesAreRegisteredOnlyInTheTestEnvironment(): void
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 5).'/config/services.yaml');
        $when = strpos($yaml, 'when@test:');
        $searchExclude = strpos($yaml, '../src/Plug/WebSearch/Adapter/TestWebSearchAdapter.php');
        $readerExclude = strpos($yaml, '../src/Service/Research/Run/Source/FixtureSourceReader.php');
        $searchService = strpos($yaml, 'App\Plug\WebSearch\Adapter\TestWebSearchAdapter:');
        $readerService = strpos($yaml, 'App\Service\Research\Run\Source\FixtureSourceReader:');

        $this->assertNotFalse($when);
        $this->assertNotFalse($searchExclude);
        $this->assertNotFalse($readerExclude);
        $this->assertNotFalse($searchService);
        $this->assertNotFalse($readerService);
        $this->assertLessThan($when, $searchExclude);
        $this->assertLessThan($when, $readerExclude);
        $this->assertGreaterThan($when, $searchService);
        $this->assertGreaterThan($when, $readerService);
    }
}
