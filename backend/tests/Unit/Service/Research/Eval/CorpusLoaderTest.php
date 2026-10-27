<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Eval;

use App\Service\Research\Eval\CorpusLoader;
use PHPUnit\Framework\TestCase;

final class CorpusLoaderTest extends TestCase
{
    public function testTheStarterSetCoversEveryCategoryAndHasTenSmokeItems(): void
    {
        $items = (new CorpusLoader())->load($this->corpusPath(), false);
        $smoke = array_values(array_filter($items, static fn ($item): bool => $item->smoke));
        $categories = [];
        $ids = [];
        foreach ($items as $item) {
            $categories[$item->category] = true;
            $ids[] = $item->id;
            $this->assertNotSame('', trim($item->question));
            $this->assertContains($item->language, ['en', 'de', 'es', 'fr', 'tr']);
        }

        $this->assertCount(20, $items);
        $this->assertCount(10, $smoke);
        $followUps = (new CorpusLoader())->followUps($this->corpusPath());
        $kinds = [];
        foreach ($followUps as $item) {
            $kinds[$item->kind] = true;
            $this->assertNotContains($item->id, $ids);
        }
        $this->assertCount(5, $followUps);
        $this->assertSame(['question', 'revise', 'deeper', 'refresh'], array_keys($kinds));
        $this->assertSame($ids, array_values(array_unique($ids)));
        foreach (CorpusLoader::CATEGORIES as $category) {
            $this->assertArrayHasKey($category, $categories);
        }
    }

    public function testSmokeLoadsOnlyTheSmokeItems(): void
    {
        $items = (new CorpusLoader())->load($this->corpusPath(), true);

        $this->assertCount(10, $items);
        foreach ($items as $item) {
            $this->assertTrue($item->smoke);
        }
    }

    public function testTheHardSetLoadsWithGoldForEveryItemAndTwoTraps(): void
    {
        $items = (new CorpusLoader())->load(dirname(__DIR__, 5).'/tests/Eval/research/corpus-hard.json', false);
        $traps = array_filter($items, static fn ($item): bool => [] !== $item->facts->forbidden);

        $this->assertCount(12, $items);
        $this->assertCount(2, $traps);
        foreach ($items as $item) {
            $this->assertContains($item->language, ['en', 'de', 'es', 'fr', 'tr']);
            $this->assertNotSame([], $item->facts->entries, $item->id);
        }
    }

    public function testARowWithoutSourcesIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'corpus');
        $this->assertIsString($path);
        file_put_contents($path, json_encode([[
            'id' => 'x',
            'category' => 'current',
            'language' => 'en',
            'question' => 'Q',
            'depth' => 'standard',
            'gold' => [],
            'smoke' => false,
        ]], JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sources');

        try {
            (new CorpusLoader())->load($path, false);
        } finally {
            unlink($path);
        }
    }

    private function corpusPath(): string
    {
        return dirname(__DIR__, 5).'/tests/Eval/research/corpus.json';
    }
}
