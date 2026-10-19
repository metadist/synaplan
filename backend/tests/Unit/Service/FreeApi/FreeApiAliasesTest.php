<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FreeApi;

use App\AI\Import\ModelImportApplier;
use App\Service\FreeApi\FreeApiAliases;
use PHPUnit\Framework\TestCase;

/**
 * The client's alias map must follow the vendored contract: every alias the
 * server offers is known here, and each alias takes over at least the
 * purpose the contract names for it.
 */
final class FreeApiAliasesTest extends TestCase
{
    private const ALIASES = __DIR__.'/../../../Fixtures/freeapi-contract/v1/aliases.json';

    public function testEveryContractAliasIsMappedToAnImportableTag(): void
    {
        $contract = $this->contractAliases();

        $this->assertSame(array_keys($contract), array_keys(FreeApiAliases::TAGS));
        foreach (FreeApiAliases::TAGS as $alias => $tag) {
            $this->assertContains($tag, ModelImportApplier::ALLOWED_TAGS, $alias);
        }
    }

    public function testEachAliasAnswersTheCapabilityTheContractNames(): void
    {
        foreach ($this->contractAliases() as $alias => $capability) {
            $this->assertSame($alias, FreeApiAliases::BINDINGS[$capability] ?? null, $capability);
        }
    }

    public function testSpeechStaysLocal(): void
    {
        $this->assertArrayNotHasKey('SOUND2TEXT', FreeApiAliases::BINDINGS);
        $this->assertArrayNotHasKey('TEXT2SOUND', FreeApiAliases::BINDINGS);
    }

    /**
     * @return array<string, string> alias id => capability
     */
    private function contractAliases(): array
    {
        $json = file_get_contents(self::ALIASES);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        $aliases = [];
        foreach ($decoded['aliases'] as $alias) {
            $aliases[$alias['id']] = $alias['capability'];
        }

        return $aliases;
    }
}
