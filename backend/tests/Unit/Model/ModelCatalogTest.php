<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class ModelCatalogTest extends TestCase
{
    public function testFindByServiceAndProviderId(): void
    {
        $results = ModelCatalog::find('groq:llama-3.3-70b-versatile');

        $this->assertNotEmpty($results);
        $this->assertSame('Groq', $results[0]['service']);
        $this->assertSame('llama-3.3-70b-versatile', $results[0]['providerId']);
    }

    public function testFindIsCaseInsensitive(): void
    {
        $lower = ModelCatalog::find('groq:llama-3.3-70b-versatile');
        $upper = ModelCatalog::find('GROQ:LLAMA-3.3-70B-VERSATILE');
        $mixed = ModelCatalog::find('Groq:Llama-3.3-70b-Versatile');

        $this->assertSame($lower, $upper);
        $this->assertSame($lower, $mixed);
    }

    public function testFindGroupedKeyReturnsAllVariants(): void
    {
        $results = ModelCatalog::find('openai:gpt-4o');

        $this->assertGreaterThan(1, count($results));
        $tags = array_column($results, 'tag');
        $this->assertContains('chat', $tags);
        $this->assertContains('pic2text', $tags);
    }

    public function testFindWithTagReturnsSpecificVariant(): void
    {
        $chatOnly = ModelCatalog::find('openai:gpt-4o:chat');

        $this->assertCount(1, $chatOnly);
        $this->assertSame('chat', $chatOnly[0]['tag']);
    }

    public function testFindUnknownKeyReturnsEmpty(): void
    {
        $this->assertSame([], ModelCatalog::find('nonexistent:model'));
    }

    public function testFindReplacesColonsInProviderIdWithDashes(): void
    {
        $results = ModelCatalog::find('ollama:deepseek-r1-14b');

        $this->assertNotEmpty($results);
        $this->assertSame('deepseek-r1:14b', $results[0]['providerId']);
    }

    public function testKeysAreUnique(): void
    {
        $keys = ModelCatalog::keys();

        $this->assertCount(count(array_unique($keys)), $keys);
    }

    public function testKeysAreSorted(): void
    {
        $keys = ModelCatalog::keys();
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys);
    }

    public function testAllModelsHaveRequiredFields(): void
    {
        $required = ['id', 'service', 'name', 'tag', 'providerId', 'selectable', 'active', 'priceIn', 'inUnit', 'priceOut', 'outUnit', 'quality', 'rating', 'json'];

        foreach (ModelCatalog::all() as $i => $model) {
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $model, "Model at index $i missing '$field'");
            }
        }
    }

    public function testAllModelIdsAreUnique(): void
    {
        $ids = array_column(ModelCatalog::all(), 'id');

        $this->assertCount(count(array_unique($ids)), $ids);
    }

    public function testUpsertCallsExecuteStatement(): void
    {
        $connection = $this->createMock(Connection::class);
        $model = ModelCatalog::find('groq:llama-3.3-70b-versatile')[0];

        // @phpstan-ignore-next-line
        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO BMODELS'),
                $this->callback(fn (array $params) => $params[0] === $model['id'] && $params[1] === $model['service'])
            );

        ModelCatalog::upsert($connection, $model);
    }

    public function testRemoveCallsDeleteById(): void
    {
        $connection = $this->createMock(Connection::class);
        $model = ModelCatalog::find('groq:llama-3.3-70b-versatile')[0];

        // @phpstan-ignore-next-line
        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM BMODELS WHERE BID = ?', [$model['id']]);

        ModelCatalog::remove($connection, $model);
    }
}
