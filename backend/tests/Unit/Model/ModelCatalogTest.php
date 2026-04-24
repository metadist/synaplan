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
        $results = ModelCatalog::find('google:gemini-2.5-pro');

        $this->assertGreaterThan(1, count($results));
        $tags = array_column($results, 'tag');
        $this->assertContains('chat', $tags);
        $this->assertContains('pic2text', $tags);
    }

    public function testFindWithTagReturnsSpecificVariant(): void
    {
        $chatOnly = ModelCatalog::find('google:gemini-2.5-pro:chat');

        $this->assertCount(1, $chatOnly);
        $this->assertSame('chat', $chatOnly[0]['tag']);
    }

    public function testFindUnknownKeyReturnsEmpty(): void
    {
        $this->assertSame([], ModelCatalog::find('nonexistent:model'));
    }

    public function testFindReplacesColonsInProviderIdWithDashes(): void
    {
        $results = ModelCatalog::find('ollama:gpt-oss-20b');

        $this->assertNotEmpty($results);
        $this->assertSame('gpt-oss:20b', $results[0]['providerId']);
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

    public function testUpsertSqlDoesNotOverwriteOperatorOwnedFields(): void
    {
        $connection = $this->createMock(Connection::class);
        $model = ModelCatalog::find('groq:llama-3.3-70b-versatile')[0];

        // @phpstan-ignore-next-line
        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(static function (string $sql): bool {
                    // Catalog-owned columns must appear in the UPDATE clause.
                    foreach (['BSERVICE', 'BNAME', 'BTAG', 'BPROVID', 'BPRICEIN', 'BPRICEOUT', 'BJSON'] as $catalogOwned) {
                        if (!str_contains($sql, sprintf('%s = VALUES(%s)', $catalogOwned, $catalogOwned))) {
                            return false;
                        }
                    }

                    // Operator-owned columns MUST NOT appear in the UPDATE clause —
                    // otherwise admin-toggled values would be wiped on every container restart.
                    [, $updateClause] = explode('ON DUPLICATE KEY UPDATE', $sql, 2);
                    foreach (['BSELECTABLE', 'BACTIVE', 'BISDEFAULT'] as $operatorOwned) {
                        if (str_contains($updateClause, sprintf('%s = VALUES(%s)', $operatorOwned, $operatorOwned))) {
                            return false;
                        }
                    }

                    return true;
                }),
            );

        ModelCatalog::upsert($connection, $model);
    }

    public function testFindBidByKeyResolvesUniqueMatch(): void
    {
        $bid = ModelCatalog::findBidByKey('openai:gpt-5.4:chat');

        $this->assertNotNull($bid);
        $found = ModelCatalog::find('openai:gpt-5.4:chat');
        $this->assertSame((int) $found[0]['id'], $bid);
    }

    public function testFindBidByKeyReturnsNullForAmbiguousKey(): void
    {
        // Bare service:providerId for openai:gpt-5.4 matches both chat + pic2text variants,
        // so it intentionally cannot resolve to a single BID — callers must add the tag suffix.
        $this->assertNull(ModelCatalog::findBidByKey('openai:gpt-5.4'));
    }

    public function testFindBidByKeyReturnsNullForUnknownKey(): void
    {
        $this->assertNull(ModelCatalog::findBidByKey('nonexistent:provider:chat'));
    }

    public function testFingerprintIsDeterministic(): void
    {
        $model = ModelCatalog::find('groq:llama-3.3-70b-versatile')[0];

        $this->assertSame(ModelCatalog::fingerprint($model), ModelCatalog::fingerprint($model));
    }

    public function testFingerprintIgnoresOperatorOwnedFields(): void
    {
        $model = ModelCatalog::find('groq:llama-3.3-70b-versatile')[0];
        $expected = ModelCatalog::fingerprint($model);

        $toggled = array_merge($model, [
            'selectable' => 0,
            'active' => 0,
            'showWhenFree' => 1,
        ]);

        $this->assertSame($expected, ModelCatalog::fingerprint($toggled));
    }

    public function testFingerprintIgnoresEmbeddedFingerprintKey(): void
    {
        $model = ModelCatalog::find('groq:llama-3.3-70b-versatile')[0];
        $expected = ModelCatalog::fingerprint($model);

        $stamped = $model;
        $stamped['json'][ModelCatalog::FINGERPRINT_KEY] = 'previously-stored-hash';

        $this->assertSame($expected, ModelCatalog::fingerprint($stamped));
    }

    public function testFingerprintChangesWhenCatalogValueChanges(): void
    {
        $model = ModelCatalog::find('groq:llama-3.3-70b-versatile')[0];
        $original = ModelCatalog::fingerprint($model);

        $model['priceIn'] = 0.99;

        $this->assertNotSame($original, ModelCatalog::fingerprint($model));
    }

    public function testFingerprintIsStableAcrossFloatRoundTrip(): void
    {
        // Doctrine DBAL hands floats back as native floats; the identity should
        // survive a string round-trip equivalent to what (float) $row['BPRICEIN']
        // produces after JSON encode/decode in the actual seed flow.
        $model = ModelCatalog::find('groq:llama-3.3-70b-versatile')[0];
        $original = ModelCatalog::fingerprint($model);

        $roundTripped = $model;
        $roundTripped['priceIn'] = (float) (string) $model['priceIn'];
        $roundTripped['priceOut'] = (float) (string) $model['priceOut'];
        $roundTripped['quality'] = (float) (string) $model['quality'];
        $roundTripped['rating'] = (float) (string) $model['rating'];

        $this->assertSame($original, ModelCatalog::fingerprint($roundTripped));
    }

    public function testUpsertEmbedsFingerprintInJsonPayload(): void
    {
        $connection = $this->createMock(Connection::class);
        $model = ModelCatalog::find('groq:llama-3.3-70b-versatile')[0];
        $expectedFingerprint = ModelCatalog::fingerprint($model);

        // @phpstan-ignore-next-line
        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->anything(),
                $this->callback(static function (array $params) use ($expectedFingerprint): bool {
                    $jsonPayload = end($params);
                    if (!is_string($jsonPayload)) {
                        return false;
                    }

                    $decoded = json_decode($jsonPayload, true);

                    return is_array($decoded)
                        && ($decoded[ModelCatalog::FINGERPRINT_KEY] ?? null) === $expectedFingerprint;
                }),
            );

        ModelCatalog::upsert($connection, $model);
    }
}
