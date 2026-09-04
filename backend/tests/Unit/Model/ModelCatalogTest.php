<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ModelCatalog;
use App\Service\CostCalculationService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModelCatalogTest extends TestCase
{
    public function testFindByServiceAndProviderId(): void
    {
        $results = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat');

        $this->assertNotEmpty($results);
        $this->assertSame('Groq', $results[0]['service']);
        $this->assertSame('qwen/qwen3.6-27b', $results[0]['providerId']);
    }

    /**
     * #1313: single canonical provider key so CamelCase catalog names and
     * lowercase comparison literals can never diverge.
     */
    public function testNormalizeProviderCollapsesCasingAndAliases(): void
    {
        $this->assertSame('anthropic', ModelCatalog::normalizeProvider('Anthropic'));
        $this->assertSame('openai', ModelCatalog::normalizeProvider('OpenAI'));
        $this->assertSame('groq', ModelCatalog::normalizeProvider('  GROQ  '));
        $this->assertSame('huggingface', ModelCatalog::normalizeProvider('Hugging Face'));
        $this->assertSame('huggingface', ModelCatalog::normalizeProvider('huggingface'));
    }

    public function testCollapseCountsByProviderMergesAliasSpellings(): void
    {
        $merged = ModelCatalog::collapseCountsByProvider([
            'huggingface' => ['active' => 2, 'total' => 4],
            'hugging face' => ['active' => 1, 'total' => 1],
            'openai' => ['active' => 3, 'total' => 3],
        ]);

        $this->assertSame([
            'huggingface' => ['active' => 3, 'total' => 5],
            'openai' => ['active' => 3, 'total' => 3],
        ], $merged);
    }

    public function testFindIsCaseInsensitive(): void
    {
        $lower = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat');
        $upper = ModelCatalog::find('GROQ:QWEN/QWEN3.6-27B:CHAT');
        $mixed = ModelCatalog::find('Groq:Qwen/Qwen3.6-27b:Chat');

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
        $results = ModelCatalog::find('ollama:gpt-oss-120b');

        $this->assertNotEmpty($results);
        $this->assertSame('gpt-oss:120b', $results[0]['providerId']);
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
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];

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

    public function testEnableInsertsMissingModel(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];

        // @phpstan-ignore-next-line
        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('INSERT INTO BMODELS'));

        ModelCatalog::enable($connection, $model);
    }

    public function testEnableExistingModelRestoresVisibilityFlagsToCatalogValues(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('42');
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];

        // Only the operator-owned visibility flags are written — an admin's
        // price or name edits must survive an enable like they survive a re-seed.
        // @phpstan-ignore-next-line
        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE BMODELS SET BACTIVE = ?, BSELECTABLE = ? WHERE BID = ?',
                [$model['active'], $model['selectable'], $model['id']]
            );

        ModelCatalog::enable($connection, $model);
    }

    /**
     * Disabling must never DELETE: BMESSAGES references the BID, and
     * ModelSeeder re-inserts any absent catalog row on the next container
     * start — which is exactly how the old DELETE-based disable silently
     * reverted itself.
     */
    public function testDisableDeactivatesExistingRowWithoutDeleting(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('42');
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];

        // @phpstan-ignore-next-line
        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE BMODELS SET BACTIVE = 0, BSELECTABLE = 0 WHERE BID = ?',
                [$model['id']]
            );

        ModelCatalog::disable($connection, $model);
    }

    public function testDisableInsertsMissingRowSoTheDeactivationSurvivesReseed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];

        $statements = [];
        $connection->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 1;
            });

        ModelCatalog::disable($connection, $model);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString('INSERT INTO BMODELS', $statements[0]);
        $this->assertStringContainsString('UPDATE BMODELS SET BACTIVE = 0, BSELECTABLE = 0', $statements[1]);
        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('DELETE', $sql);
        }
    }

    public function testFindByServiceMatchesCaseInsensitivelyAndCollapsesAliases(): void
    {
        $lower = ModelCatalog::findByService('groq');
        $upper = ModelCatalog::findByService('  GROQ ');

        $this->assertNotEmpty($lower);
        $this->assertSame($lower, $upper);
        $this->assertSame(['Groq'], array_unique(array_column($lower, 'service')));

        // The 'Hugging Face' alias must resolve like the canonical name (#1313).
        $this->assertSame(
            ModelCatalog::findByService('huggingface'),
            ModelCatalog::findByService('Hugging Face')
        );

        $this->assertSame([], ModelCatalog::findByService('skynet'));
    }

    public function testServiceNamesAreKeyedByNormalizedName(): void
    {
        $names = ModelCatalog::serviceNames();

        $this->assertSame('Groq', $names['groq']);
        $this->assertSame('OpenAI', $names['openai']);
        $this->assertSame('Ollama', $names['ollama']);

        foreach (array_keys($names) as $key) {
            $this->assertSame(ModelCatalog::normalizeProvider($key), $key);
        }
    }

    public function testUpsertSqlDoesNotOverwriteOperatorOwnedFields(): void
    {
        $connection = $this->createMock(Connection::class);
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];

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

    /**
     * GPT-6 Astra — OpenAI flagship added 2026-09-04. Chat + vision share the
     * same upstream id, official $10/$50 per-1M pricing, $1/1M cached input,
     * and the >272k long-context 2x/1.5x tier via CONTEXT_PRICING.
     */
    public function testGpt6AstraModelsAreAvailableWithExpectedApiIds(): void
    {
        $astra = ModelCatalog::find('openai:gpt-6-astra');

        $this->assertCount(2, $astra, 'Expected gpt-6-astra chat + vision variants');
        $this->assertSame(['chat', 'pic2text'], array_column($astra, 'tag'));
        $this->assertNotNull(ModelCatalog::findBidByKey('openai:gpt-6-astra:chat'));
        $this->assertNotNull(ModelCatalog::findBidByKey('openai:gpt-6-astra:pic2text'));
        $this->assertNull(ModelCatalog::findBidByKey('openai:gpt-6-astra'));

        foreach ($astra as $variant) {
            $this->assertSame('OpenAI', $variant['service']);
            $this->assertSame('gpt-6-astra', $variant['providerId']);
            $this->assertSame('gpt-6-astra', $variant['json']['params']['model'] ?? null);
            $this->assertEqualsWithDelta(10.0, (float) $variant['priceIn'], 1e-9);
            $this->assertEqualsWithDelta(50.0, (float) $variant['priceOut'], 1e-9);
            $this->assertEqualsWithDelta(1.0, (float) ($variant['json']['cache_read_price_per_1M'] ?? 0.0), 1e-9);
            $this->assertSame('responses', $variant['json']['meta']['api'] ?? null);
            $this->assertSame('1050000', $variant['json']['meta']['context_window'] ?? null);
            $this->assertContains('reasoning', $variant['json']['features'] ?? []);
        }

        $chat = ModelCatalog::find('openai:gpt-6-astra:chat')[0];
        $this->assertContains('tool_use', $chat['json']['features'] ?? []);
        $this->assertSame('medium', $chat['json']['meta']['reasoning_effort_default'] ?? null);

        $tier = ModelCatalog::contextPricing('gpt-6-astra');
        $this->assertNotNull($tier);
        $this->assertSame(272000, $tier['threshold_tokens']);
        $this->assertEqualsWithDelta(20.0, $tier['price_in_above'], 1e-9);
        $this->assertEqualsWithDelta(75.0, $tier['price_out_above'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $tier['cache_price_in_above'] ?? 0.0, 1e-9);
    }

    /**
     * Official cached-input rates per 1M tokens, verified against
     * https://developers.openai.com/api/docs/pricing and
     * https://ai.google.dev/gemini-api/docs/pricing on 2026-09-04.
     *
     * Every one of these reads at 0.1x the input rate. CostCalculationService
     * falls back to 50% when a row authors nothing, which over-billed cached
     * tokens 5x across the whole GPT-5+ and Gemini Pro lineup — so an explicit
     * price on each row is what keeps billing correct, not a nice-to-have.
     *
     * @return array<string, array{0: string, 1: float}>
     */
    public static function cachedInputRateProvider(): array
    {
        return [
            'gpt-6-astra' => ['openai:gpt-6-astra', 1.00],
            'gpt-5.6-sol' => ['openai:gpt-5.6-sol', 0.40],
            'gpt-5.6-terra' => ['openai:gpt-5.6-terra', 0.20],
            'gpt-5.6-luna' => ['openai:gpt-5.6-luna', 0.02],
            'gpt-5.5' => ['openai:gpt-5.5', 0.50],
            'gpt-5.4' => ['openai:gpt-5.4', 0.25],
            'gpt-5.4-mini' => ['openai:gpt-5.4-mini', 0.075],
            'gpt-5.4-nano' => ['openai:gpt-5.4-nano', 0.02],
            'gemini-2.5-pro' => ['google:gemini-2.5-pro', 0.125],
            'gemini-3.1-pro' => ['google:gemini-3.1-pro-preview', 0.20],
        ];
    }

    #[DataProvider('cachedInputRateProvider')]
    public function testCachedInputRateIsAuthoredOnEveryVariant(string $key, float $expected): void
    {
        $variants = ModelCatalog::find($key);
        $this->assertNotEmpty($variants, "No catalog rows for {$key}");

        foreach ($variants as $variant) {
            $authored = $variant['json']['cache_read_price_per_1M'] ?? null;
            $this->assertNotNull(
                $authored,
                sprintf('%s (%s) authors no cache_read_price_per_1M and would fall back to 50%%', $key, $variant['tag']),
            );
            $this->assertEqualsWithDelta($expected, (float) $authored, 1e-9);
        }
    }

    /**
     * Every provider that raises input and output above a context threshold
     * raises the cached-input rate with it, always to exactly 2x the short-context
     * rate. gpt-5.5-pro is the sole exception — it offers no cached discount at
     * all, so there is no rate to lift.
     */
    public function testLongContextTiersDoubleTheCachedInputRate(): void
    {
        $providerIds = array_unique(array_column(ModelCatalog::all(), 'providerId'));

        $checked = 0;
        foreach ($providerIds as $providerId) {
            $tier = ModelCatalog::contextPricing($providerId);
            if (null === $tier) {
                continue;
            }

            $rows = array_values(array_filter(
                ModelCatalog::all(),
                static fn (array $row): bool => $row['providerId'] === $providerId,
            ));
            $baseCache = $rows[0]['json']['cache_read_price_per_1M'] ?? null;

            if (null === $baseCache) {
                $this->assertArrayNotHasKey(
                    'cache_price_in_above',
                    $tier,
                    sprintf('%s has a long-context cache rate but no base cache rate', $providerId),
                );
                continue;
            }

            $this->assertArrayHasKey(
                'cache_price_in_above',
                $tier,
                sprintf('%s caches reads but its long-context tier does not raise the cache rate', $providerId),
            );
            $this->assertEqualsWithDelta(
                2 * (float) $baseCache,
                $tier['cache_price_in_above'],
                1e-9,
                sprintf('%s long-context cache rate should be 2x the short-context rate', $providerId),
            );
            ++$checked;
        }

        $this->assertGreaterThan(0, $checked, 'Expected at least one tiered model with a cache rate');
    }

    /**
     * The 1.25x cache-write charge starts with GPT-5.6; GPT-5.5 and earlier incur
     * "no additional cache-write charge". Authoring the multiplier on the wrong
     * row silently over-bills, so pin which rows carry it.
     */
    public function testCacheWriteMultiplierIsAuthoredOnlyForChargingFamilies(): void
    {
        $charging = ['gpt-6-astra', 'gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna'];

        foreach (ModelCatalog::all() as $row) {
            $authored = $row['json']['cache_write_multiplier'] ?? null;

            if (in_array($row['providerId'], $charging, true)) {
                $this->assertEqualsWithDelta(1.25, (float) $authored, 1e-9, sprintf(
                    '%s (%s) must bill cache writes at 1.25x',
                    $row['providerId'],
                    $row['tag'],
                ));
                continue;
            }

            $this->assertNull($authored, sprintf(
                '%s (%s) authors a cache-write multiplier but is not billed for cache writes',
                $row['providerId'],
                $row['tag'],
            ));
        }
    }

    public function testGpt55ModelsAreAvailableWithExpectedApiIds(): void
    {
        $gpt55 = ModelCatalog::find('openai:gpt-5.5');
        $gpt55Pro = ModelCatalog::find('openai:gpt-5.5-pro');

        $this->assertCount(2, $gpt55, 'Expected gpt-5.5 chat + vision variants');
        $this->assertCount(2, $gpt55Pro, 'Expected gpt-5.5-pro chat + vision variants');
        $this->assertSame(['chat', 'pic2text'], array_column($gpt55, 'tag'));
        $this->assertSame(['chat', 'pic2text'], array_column($gpt55Pro, 'tag'));

        // Both variants of each family must talk to the same upstream OpenAI model id.
        foreach ($gpt55 as $variant) {
            $this->assertSame('gpt-5.5', $variant['providerId']);
            $this->assertSame('gpt-5.5', $variant['json']['params']['model'] ?? null);
        }
        foreach ($gpt55Pro as $variant) {
            $this->assertSame('gpt-5.5-pro', $variant['providerId']);
            $this->assertSame('gpt-5.5-pro', $variant['json']['params']['model'] ?? null);
        }
    }

    public function testClaudeFable5ModelsAreAvailableWithExpectedApiIds(): void
    {
        $fable5 = ModelCatalog::find('anthropic:claude-fable-5');

        $this->assertCount(2, $fable5, 'Expected claude-fable-5 chat + vision variants');
        $this->assertSame(['chat', 'pic2text'], array_column($fable5, 'tag'));

        foreach ($fable5 as $variant) {
            $this->assertSame('Anthropic', $variant['service']);
            $this->assertSame('claude-fable-5', $variant['providerId']);
            $this->assertSame('claude-fable-5', $variant['json']['params']['model'] ?? null);
            $this->assertEqualsWithDelta(10.0, (float) $variant['priceIn'], 1e-9);
            $this->assertEqualsWithDelta(50.0, (float) $variant['priceOut'], 1e-9);
        }
    }

    /**
     * Claude Fable 5.1 — successor to Fable 5 at the same input/output price.
     * Cache reads are priced at a quarter of Fable 5's implicit rate
     * (0.25 vs the 1.0 = 10 * 0.1 the Anthropic-wide discount would otherwise
     * apply), so the catalog carries an explicit `cache_read_price_per_1M`
     * override that CostCalculationService::getPriceSnapshot() picks up
     * ahead of the per-provider discount.
     */
    public function testClaudeFable51ModelsAreAvailableWithExpectedApiIds(): void
    {
        $fable51 = ModelCatalog::find('anthropic:claude-fable-5-1');

        $this->assertCount(2, $fable51, 'Expected claude-fable-5-1 chat + vision variants');
        $this->assertSame(['chat', 'pic2text'], array_column($fable51, 'tag'));
        $this->assertNotNull(ModelCatalog::findBidByKey('anthropic:claude-fable-5-1:chat'));
        $this->assertNotNull(ModelCatalog::findBidByKey('anthropic:claude-fable-5-1:pic2text'));

        foreach ($fable51 as $variant) {
            $this->assertSame('Anthropic', $variant['service']);
            $this->assertSame('claude-fable-5-1', $variant['providerId']);
            $this->assertSame('claude-fable-5-1', $variant['json']['params']['model'] ?? null);
            $this->assertEqualsWithDelta(10.0, (float) $variant['priceIn'], 1e-9);
            $this->assertEqualsWithDelta(50.0, (float) $variant['priceOut'], 1e-9);
            $this->assertEqualsWithDelta(0.25, (float) ($variant['json']['cache_read_price_per_1M'] ?? 0.0), 1e-9);
        }
    }

    /**
     * Sonnet 5 also backs the MEM-tagged memory-extraction row (BID 222), so the
     * bare `service:providerId` key resolves to three variants — capability
     * bindings must therefore always use the tag-qualified key.
     */
    public function testClaudeSonnet5ModelsAreAvailableWithExpectedApiIds(): void
    {
        $sonnet5 = ModelCatalog::find('anthropic:claude-sonnet-5');
        $tags = array_column($sonnet5, 'tag');
        sort($tags);

        $this->assertCount(3, $sonnet5, 'Expected claude-sonnet-5 chat + vision + memory-extraction variants');
        $this->assertSame(['chat', 'mem', 'pic2text'], $tags);
        $this->assertNotNull(ModelCatalog::findBidByKey('anthropic:claude-sonnet-5:chat'));
        $this->assertNotNull(ModelCatalog::findBidByKey('anthropic:claude-sonnet-5:pic2text'));

        foreach ($sonnet5 as $variant) {
            $this->assertSame('Anthropic', $variant['service']);
            $this->assertSame('claude-sonnet-5', $variant['providerId']);
            $this->assertSame('claude-sonnet-5', $variant['json']['params']['model'] ?? null);
            $this->assertEqualsWithDelta(2.0, (float) $variant['priceIn'], 1e-9);
            $this->assertEqualsWithDelta(10.0, (float) $variant['priceOut'], 1e-9);
        }
    }

    /**
     * TrustedTokens (TNG, Germany) — chat + vision rows from
     * https://trustedtokens.eu/api/billing/models (snapshot 2026-08-29).
     * Provider ids keep the upstream org/name form. Prices are USD/1M.
     */
    public function testTrustedTokensModelsAreAvailableWithExpectedApiIds(): void
    {
        $glm = ModelCatalog::find('trustedtokens:zai-org/glm-5.2:chat');
        $glm53 = ModelCatalog::find('trustedtokens:zai-org/glm-5.3:chat');
        $glm53Flash = ModelCatalog::find('trustedtokens:zai-org/glm-5.3-flash:chat');
        $glm53FlashVision = ModelCatalog::find('trustedtokens:zai-org/glm-5.3-flash:pic2text');
        $chimera = ModelCatalog::find('trustedtokens:tngtech/deepseek-tng-r1t2-chimera:chat');
        $v4Flash = ModelCatalog::find('trustedtokens:deepseek-ai/deepseek-v4-flash:chat');
        $v4Flash0731 = ModelCatalog::find('trustedtokens:deepseek-ai/deepseek-v4-flash-0731:chat');
        $v4Pro = ModelCatalog::find('trustedtokens:deepseek-ai/deepseek-v4-pro-0813:chat');
        $qwenChat = ModelCatalog::find('trustedtokens:qwen/qwen3.6-35b-a3b-fp8:chat');
        $qwenVision = ModelCatalog::find('trustedtokens:qwen/qwen3.6-35b-a3b-fp8:pic2text');
        $gptOss = ModelCatalog::find('trustedtokens:openai/gpt-oss-120b:chat');

        $this->assertCount(1, $glm);
        $this->assertCount(1, $glm53);
        $this->assertCount(1, $glm53Flash);
        $this->assertCount(1, $glm53FlashVision);
        $this->assertCount(1, $chimera);
        $this->assertCount(1, $v4Flash);
        $this->assertCount(1, $v4Flash0731);
        $this->assertCount(1, $v4Pro);
        $this->assertCount(1, $qwenChat);
        $this->assertCount(1, $qwenVision);
        $this->assertCount(1, $gptOss);

        $this->assertSame(331, $glm53[0]['id']);
        $this->assertSame('zai-org/GLM-5.2', $glm[0]['providerId']);
        $this->assertSame('zai-org/GLM-5.3', $glm53[0]['providerId']);
        $this->assertSame('zai-org/GLM-5.3-Flash', $glm53Flash[0]['providerId']);
        $this->assertSame('zai-org/GLM-5.3-Flash', $glm53FlashVision[0]['providerId']);
        $this->assertSame('tngtech/DeepSeek-TNG-R1T2-Chimera', $chimera[0]['providerId']);
        $this->assertSame('deepseek-ai/DeepSeek-V4-Flash', $v4Flash[0]['providerId']);
        $this->assertSame('deepseek-ai/DeepSeek-V4-Flash-0731', $v4Flash0731[0]['providerId']);
        $this->assertSame('deepseek-ai/DeepSeek-V4-Pro-0813', $v4Pro[0]['providerId']);
        $this->assertSame('Qwen/Qwen3.6-35B-A3B-FP8', $qwenChat[0]['providerId']);
        $this->assertSame('openai/gpt-oss-120b', $gptOss[0]['providerId']);

        $this->assertEqualsWithDelta(1.50, (float) $glm[0]['priceIn'], 1e-9);
        $this->assertEqualsWithDelta(4.50, (float) $glm[0]['priceOut'], 1e-9);
        $this->assertEqualsWithDelta(1.50, (float) $glm53[0]['priceIn'], 1e-9);
        $this->assertEqualsWithDelta(4.50, (float) $glm53[0]['priceOut'], 1e-9);
        $this->assertEqualsWithDelta(0.15, (float) $glm53Flash[0]['priceIn'], 1e-9);
        $this->assertEqualsWithDelta(0.30, (float) $glm53Flash[0]['priceOut'], 1e-9);
        $this->assertEqualsWithDelta(0.15, (float) $glm53FlashVision[0]['priceIn'], 1e-9);
        $this->assertEqualsWithDelta(0.30, (float) $glm53FlashVision[0]['priceOut'], 1e-9);
        $this->assertEqualsWithDelta(1.00, (float) $chimera[0]['priceIn'], 1e-9);
        $this->assertEqualsWithDelta(3.00, (float) $chimera[0]['priceOut'], 1e-9);
        $this->assertEqualsWithDelta(0.15, (float) $v4Flash[0]['priceIn'], 1e-9);
        $this->assertEqualsWithDelta(0.30, (float) $v4Flash[0]['priceOut'], 1e-9);
        $this->assertEqualsWithDelta(0.15, (float) $v4Flash0731[0]['priceIn'], 1e-9);
        $this->assertEqualsWithDelta(0.30, (float) $v4Flash0731[0]['priceOut'], 1e-9);
        $this->assertEqualsWithDelta(2.25, (float) $v4Pro[0]['priceIn'], 1e-9);
        $this->assertEqualsWithDelta(6.75, (float) $v4Pro[0]['priceOut'], 1e-9);
        $this->assertEqualsWithDelta(0.25, (float) $qwenChat[0]['priceIn'], 1e-9);
        $this->assertEqualsWithDelta(1.50, (float) $qwenChat[0]['priceOut'], 1e-9);
        $this->assertEqualsWithDelta(0.15, (float) $gptOss[0]['priceIn'], 1e-9);
        $this->assertEqualsWithDelta(0.60, (float) $gptOss[0]['priceOut'], 1e-9);

        // Cache-read pricing for EVERY new row — a drift in any of them would
        // silently mischarge cached tokens (Chimera's 0.20 is the only
        // distinct value and the most drift-prone).
        $this->assertEqualsWithDelta(0.30, (float) ($glm53[0]['json']['cache_read_price_per_1M'] ?? 0), 1e-9);
        $this->assertEqualsWithDelta(0.03, (float) ($glm53Flash[0]['json']['cache_read_price_per_1M'] ?? 0), 1e-9);
        $this->assertEqualsWithDelta(0.03, (float) ($glm53FlashVision[0]['json']['cache_read_price_per_1M'] ?? 0), 1e-9);
        $this->assertEqualsWithDelta(0.20, (float) ($chimera[0]['json']['cache_read_price_per_1M'] ?? 0), 1e-9);
        $this->assertEqualsWithDelta(0.03, (float) ($v4Flash[0]['json']['cache_read_price_per_1M'] ?? 0), 1e-9);
        $this->assertEqualsWithDelta(0.03, (float) ($v4Flash0731[0]['json']['cache_read_price_per_1M'] ?? 0), 1e-9);
        $this->assertEqualsWithDelta(0.45, (float) ($v4Pro[0]['json']['cache_read_price_per_1M'] ?? 0), 1e-9);

        $rows = [
            $glm[0], $glm53[0], $glm53Flash[0], $glm53FlashVision[0],
            $chimera[0], $v4Flash[0], $v4Flash0731[0], $v4Pro[0],
            $qwenChat[0], $qwenVision[0], $gptOss[0],
        ];
        foreach ($rows as $row) {
            $this->assertSame('TrustedTokens', $row['service']);
            $this->assertSame('DE', $row['json']['meta']['jurisdiction'] ?? null);
        }
    }

    public function testClaudeOpus5ModelsAreAvailableWithExpectedApiIds(): void
    {
        $opus5 = ModelCatalog::find('anthropic:claude-opus-5');

        $this->assertCount(2, $opus5, 'Expected claude-opus-5 chat + vision variants');
        $this->assertSame(['chat', 'pic2text'], array_column($opus5, 'tag'));

        foreach ($opus5 as $variant) {
            $this->assertSame('Anthropic', $variant['service']);
            $this->assertSame('claude-opus-5', $variant['providerId']);
            $this->assertSame('claude-opus-5', $variant['json']['params']['model'] ?? null);
            $this->assertEqualsWithDelta(5.0, (float) $variant['priceIn'], 1e-9);
            $this->assertEqualsWithDelta(25.0, (float) $variant['priceOut'], 1e-9);
        }
    }

    /**
     * xAI Grok 4.6 — flagship chat + vision rows added 2026-08-20. Both talk to
     * the same upstream model id, carry the official $2/$6 per-1M pricing with a
     * $0.50/1M cache-read rate, and share the >200k long-context 2x tier via
     * CONTEXT_PRICING (keyed by providerId, so one entry covers both rows).
     */
    public function testGrok46ModelsAreAvailableWithExpectedApiIds(): void
    {
        $grok46 = ModelCatalog::find('xai:grok-4.6');

        $this->assertCount(2, $grok46, 'Expected grok-4.6 chat + vision variants');
        $this->assertSame(['chat', 'pic2text'], array_column($grok46, 'tag'));
        $this->assertNotNull(ModelCatalog::findBidByKey('xai:grok-4.6:chat'));
        $this->assertNotNull(ModelCatalog::findBidByKey('xai:grok-4.6:pic2text'));

        foreach ($grok46 as $variant) {
            $this->assertSame('xAI', $variant['service']);
            $this->assertSame('grok-4.6', $variant['providerId']);
            $this->assertSame('grok-4.6', $variant['json']['params']['model'] ?? null);
            $this->assertEqualsWithDelta(2.0, (float) $variant['priceIn'], 1e-9);
            $this->assertEqualsWithDelta(6.0, (float) $variant['priceOut'], 1e-9);
            $this->assertEqualsWithDelta(0.50, (float) ($variant['json']['cache_read_price_per_1M'] ?? 0.0), 1e-9);
        }

        $tier = ModelCatalog::contextPricing('grok-4.6');
        $this->assertNotNull($tier);
        $this->assertSame(200000, $tier['threshold_tokens']);
        $this->assertEqualsWithDelta(4.0, $tier['price_in_above'], 1e-9);
        $this->assertEqualsWithDelta(12.0, $tier['price_out_above'], 1e-9);
    }

    /**
     * Kimi K3 via the HF router — like every Kimi row, pinned to DeepInfra
     * (`:deepinfra` suffix) so the billed price is deterministic and matches
     * the catalog rate (DeepInfra snapshot 2026-08-20). K3 outputs text only,
     * so exactly chat + pic2text variants exist — no text2pic.
     */
    public function testKimiK3ModelsAreAvailableWithExpectedApiIds(): void
    {
        $k3 = ModelCatalog::find('huggingface:moonshotai/Kimi-K3-deepinfra');

        $this->assertCount(2, $k3, 'Expected Kimi K3 chat + vision variants');
        $this->assertSame(['chat', 'pic2text'], array_column($k3, 'tag'));
        $this->assertNotNull(ModelCatalog::findBidByKey('huggingface:moonshotai/Kimi-K3-deepinfra:chat'));
        $this->assertNotNull(ModelCatalog::findBidByKey('huggingface:moonshotai/Kimi-K3-deepinfra:pic2text'));

        foreach ($k3 as $variant) {
            $this->assertSame('HuggingFace', $variant['service']);
            $this->assertSame('moonshotai/Kimi-K3:deepinfra', $variant['providerId']);
            $this->assertSame('moonshotai/Kimi-K3:deepinfra', $variant['json']['params']['model'] ?? null);
            $this->assertEqualsWithDelta(2.85, (float) $variant['priceIn'], 1e-9);
            $this->assertEqualsWithDelta(14.25, (float) $variant['priceOut'], 1e-9);
            $this->assertTrue($variant['json']['meta']['forced_thinking'] ?? false, 'K3 always thinks — the provider relies on this flag');
        }
    }

    /**
     * The pre-4.8 Claude generations were retired in favour of Opus 4.8 and the
     * 5-series (deactivated in existing installs by Version20260727120000). Their
     * BIDs must never come back: BMESSAGES rows still reference them, and the
     * migration's BPROVID guards assume the upstream model ids are gone from the
     * catalog.
     */
    public function testRetiredClaudeGenerationsAreAbsentFromCatalog(): void
    {
        $providerIds = array_column(ModelCatalog::all(), 'providerId');

        $retiredProviderIds = [
            'claude-opus-4-1-20250805',
            'claude-opus-4-5',
            'claude-opus-4-6',
            'claude-opus-4-7',
            'claude-sonnet-4-5-20250929',
            'claude-sonnet-4-6',
        ];
        foreach ($retiredProviderIds as $retired) {
            $this->assertNotContains($retired, $providerIds, sprintf('%s was retired and must not be re-added.', $retired));
        }

        $ids = array_column(ModelCatalog::all(), 'id');
        foreach ([69, 93, 109, 112, 121, 160, 161, 163, 164, 165, 166] as $retiredBid) {
            $this->assertNotContains($retiredBid, $ids, sprintf('BID %d belongs to a retired model and must not be reused.', $retiredBid));
        }
    }

    /**
     * OpenAI gpt-4.1 (BID 30) and Groq llama-4-maverick (BID 49) are superseded and
     * deactivated by Version20260727180000. Re-adding either — under its old BID or
     * its upstream model id — would resurrect a model we deliberately took out of
     * the picker.
     */
    public function testSupersededOpenAiAndGroqModelsAreAbsentFromCatalog(): void
    {
        $providerIds = array_column(ModelCatalog::all(), 'providerId');
        $this->assertNotContains('gpt-4.1', $providerIds);
        $this->assertNotContains('meta-llama/llama-4-maverick-17b-128e-instruct', $providerIds);

        $ids = array_column(ModelCatalog::all(), 'id');
        $this->assertNotContains(30, $ids);
        $this->assertNotContains(49, $ids);
    }

    /**
     * Groq shut down llama-3.3-70b-versatile, llama-3.1-8b-instant (08/16/26),
     * llama-4-scout and qwen3-32b (07/17/26); Version20260819080000 deactivates
     * them in existing installs. Re-adding one — under its old BID or its
     * upstream model id — would resurrect a model whose API requests now fail.
     */
    public function testShutDownGroqModelsAreAbsentFromCatalog(): void
    {
        $providerIds = array_column(ModelCatalog::all(), 'providerId');
        $ids = array_column(ModelCatalog::all(), 'id');

        $retired = [
            9 => 'llama-3.3-70b-versatile',
            17 => 'meta-llama/llama-4-scout-17b-16e-instruct',
            53 => 'qwen/qwen3-32b',
            236 => 'llama-3.1-8b-instant',
        ];
        foreach ($retired as $retiredBid => $retiredProviderId) {
            $this->assertNotContains($retiredProviderId, $providerIds, sprintf('%s was shut down by Groq and must not be re-added.', $retiredProviderId));
            $this->assertNotContains($retiredBid, $ids, sprintf('BID %d belongs to a retired model and must not be reused.', $retiredBid));
        }
    }

    /**
     * Groq Qwen 3.6 27B — the replacement for the retired Llama 3.3 70B /
     * Qwen3 32B (chat) and Llama 4 Scout (vision) rows. Both variants must talk
     * to the same upstream model id and carry the official Groq pricing.
     */
    public function testGroqQwen36ModelsAreAvailableWithExpectedApiIds(): void
    {
        $qwen = ModelCatalog::find('groq:qwen/qwen3.6-27b');

        $this->assertCount(2, $qwen, 'Expected Qwen 3.6 27B chat + vision variants');
        $this->assertSame(['chat', 'pic2text'], array_column($qwen, 'tag'));
        $this->assertNotNull(ModelCatalog::findBidByKey('groq:qwen/qwen3.6-27b:chat'));
        $this->assertNotNull(ModelCatalog::findBidByKey('groq:qwen/qwen3.6-27b:pic2text'));

        foreach ($qwen as $variant) {
            $this->assertSame('Groq', $variant['service']);
            $this->assertSame('qwen/qwen3.6-27b', $variant['providerId']);
            $this->assertSame('qwen/qwen3.6-27b', $variant['json']['params']['model'] ?? null);
            $this->assertEqualsWithDelta(0.60, (float) $variant['priceIn'], 1e-9);
            $this->assertEqualsWithDelta(3.00, (float) $variant['priceOut'], 1e-9);
        }
    }

    /**
     * The remaining orphans found on the production catalog on 2026-07-28 and
     * deactivated by Version20260728120000. Same reasoning as the test above:
     * re-adding one under its old BID or upstream model id would resurrect a row
     * we deliberately took out of the picker.
     */
    public function testRetiredOpenAiAndHuggingFaceOrphansAreAbsentFromCatalog(): void
    {
        $providerIds = array_column(ModelCatalog::all(), 'providerId');
        $ids = array_column(ModelCatalog::all(), 'id');

        $retired = [
            70 => 'gpt-5',
            106 => 'gpt-5.2-2025-12-11',
            125 => 'deepseek-ai/DeepSeek-R1',
            126 => 'stabilityai/stable-diffusion-xl-base-1.0',
            128 => 'Qwen/Qwen2.5-Coder-32B-Instruct',
            129 => 'intfloat/multilingual-e5-large',
            150 => 'gpt-5-mini',
        ];
        foreach ($retired as $retiredBid => $retiredProviderId) {
            $this->assertNotContains($retiredProviderId, $providerIds, sprintf('%s was retired and must not be re-added.', $retiredProviderId));
            $this->assertNotContains($retiredBid, $ids, sprintf('BID %d belongs to a retired model and must not be reused.', $retiredBid));
        }
    }

    public function testGpt55ProModelsAreMarkedAsNonStreaming(): void
    {
        $chat = ModelCatalog::find('openai:gpt-5.5-pro:chat')[0];
        $vision = ModelCatalog::find('openai:gpt-5.5-pro:pic2text')[0];

        $this->assertFalse($chat['json']['supportsStreaming']);
        $this->assertFalse($vision['json']['supportsStreaming']);
    }

    /**
     * A non-zero price authored under a unit that normalises to 0 is silently free:
     * `normaliseToPerUnit()` maps '-', '' and 'free' to 0.0, so the price is stored,
     * shown, and never billed. Two Ollama rows shipped exactly that combination and
     * gave away their output tokens until Version20260727190000 fixed the unit.
     *
     * Note `per_generation` and friends are fine — unknown units fall through as
     * per-1, which is what a flat per-clip fee needs.
     */
    public function testNoCatalogPriceIsAuthoredUnderAUnitThatNormalisesToZero(): void
    {
        foreach (ModelCatalog::all() as $model) {
            foreach ([['priceIn', 'inUnit'], ['priceOut', 'outUnit']] as [$priceField, $unitField]) {
                $price = (float) ($model[$priceField] ?? 0.0);
                if ($price <= 0.0) {
                    continue;
                }

                $unit = (string) ($model[$unitField] ?? '');
                $this->assertNotSame(
                    0.0,
                    CostCalculationService::normaliseToPerUnit($price, $unit),
                    sprintf(
                        'BID %s (%s) authors %s=%s under %s="%s", which normalises to 0 — the price would never be billed.',
                        (string) ($model['id'] ?? '?'),
                        (string) ($model['name'] ?? '?'),
                        $priceField,
                        (string) $price,
                        $unitField,
                        $unit,
                    ),
                );
            }
        }
    }

    public function testFingerprintIsDeterministic(): void
    {
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];

        $this->assertSame(ModelCatalog::fingerprint($model), ModelCatalog::fingerprint($model));
    }

    public function testFingerprintIgnoresOperatorOwnedFields(): void
    {
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];
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
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];
        $expected = ModelCatalog::fingerprint($model);

        $stamped = $model;
        $stamped['json'][ModelCatalog::FINGERPRINT_KEY] = 'previously-stored-hash';

        $this->assertSame($expected, ModelCatalog::fingerprint($stamped));
    }

    public function testFingerprintChangesWhenCatalogValueChanges(): void
    {
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];
        $original = ModelCatalog::fingerprint($model);

        $model['priceIn'] = 0.99;

        $this->assertNotSame($original, ModelCatalog::fingerprint($model));
    }

    /**
     * Regression test for issue #886a: image models that the upstream
     * provider bills as a flat per-image fee MUST set
     * `pricing_mode: per_image` so the media-cost path runs. Live prod
     * BMODELS confirms exactly one such row in the current catalog —
     * Google Imagen 4.0 (BID 115). OpenAI gpt-image-* and Google Nano
     * Banana stay on the implicit per-token default because the provider
     * bills them as tokens; TheHive entries stay on the implicit default
     * because they're routed through a flat-rate operator agreement.
     *
     * Pinning the explicit allow-list here (rather than "every text2pic")
     * stops a future contributor from blanket-flagging providers whose
     * upstream billing is actually token-based — which would re-introduce
     * the catastrophic-overbill class of bug Copilot flagged on PR #932.
     */
    public function testImagenFourHasPerImagePricingMode(): void
    {
        $imagen = array_values(array_filter(
            ModelCatalog::all(),
            static fn (array $m): bool => 'imagen-4.0-generate-001' === ($m['providerId'] ?? null),
        ));

        $this->assertCount(1, $imagen, 'Catalog must contain exactly one Imagen 4.0 entry.');
        $this->assertSame('per_image', $imagen[0]['json']['pricing_mode'] ?? null);
        $this->assertSame('perImage', $imagen[0]['outUnit'] ?? null);
        $this->assertEqualsWithDelta(0.04, (float) ($imagen[0]['priceOut'] ?? 0.0), 1e-9);
    }

    /**
     * Regression test for issue #886b: TTS models that the upstream
     * provider bills as a flat per-character fee MUST set
     * `pricing_mode: per_character` so the media-cost path runs. Live
     * prod BMODELS confirms exactly two such rows in the current catalog
     * — OpenAI tts-1 (BID 41) and tts-1-hd (BID 83). Google Gemini 2.5
     * Flash TTS bills as tokens (per_token default); Piper is operator-
     * hosted with an effectively free price.
     *
     * Pinning the explicit allow-list here (rather than "every text2sound")
     * stops a future contributor from blanket-flagging providers whose
     * upstream billing is actually token-based — which would re-introduce
     * the catastrophic-overbill class of bug Copilot flagged on PR #933.
     */
    public function testOpenAiTtsModelsHavePerCharacterPricingMode(): void
    {
        $expected = [
            'tts-1' => 0.000015,
            'tts-1-hd' => 0.00003,
        ];

        foreach ($expected as $providerId => $expectedPriceIn) {
            $rows = array_values(array_filter(
                ModelCatalog::all(),
                static fn (array $m): bool => $providerId === ($m['providerId'] ?? null),
            ));

            $this->assertCount(1, $rows, sprintf('Catalog must contain exactly one %s entry.', $providerId));
            $this->assertSame('per_character', $rows[0]['json']['pricing_mode'] ?? null);
            $this->assertSame('perChar', $rows[0]['inUnit'] ?? null);
            $this->assertEqualsWithDelta(
                $expectedPriceIn,
                (float) ($rows[0]['priceIn'] ?? 0.0),
                1e-9,
                sprintf('%s priceIn must be authored per-character (matches live BMODELS).', $providerId),
            );
        }
    }

    public function testFingerprintIsStableAcrossFloatRoundTrip(): void
    {
        // Doctrine DBAL hands floats back as native floats; the identity should
        // survive a string round-trip equivalent to what (float) $row['BPRICEIN']
        // produces after JSON encode/decode in the actual seed flow.
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];
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
        $model = ModelCatalog::find('groq:qwen/qwen3.6-27b:chat')[0];
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
