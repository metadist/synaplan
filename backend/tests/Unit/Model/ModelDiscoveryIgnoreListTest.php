<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ModelDiscoveryIgnoreList;
use PHPUnit\Framework\TestCase;

final class ModelDiscoveryIgnoreListTest extends TestCase
{
    public function testExactEntriesStartEmpty(): void
    {
        $this->assertSame([], ModelDiscoveryIgnoreList::entries());
    }

    public function testKeyIsLowercasedProviderColonId(): void
    {
        $this->assertSame('openai:gpt-6-sol-pro', ModelDiscoveryIgnoreList::key('OpenAI', 'GPT-6-Sol-Pro'));
    }

    public function testContainsUsesExactKey(): void
    {
        $this->assertFalse(ModelDiscoveryIgnoreList::contains('openai', 'anything'));
    }

    public function testClassRulesMatchExpectedPrefixes(): void
    {
        $this->assertNotNull(ModelDiscoveryIgnoreList::matchingClassRule('openai', 'ft:personal-abc'));
        $this->assertNotNull(ModelDiscoveryIgnoreList::matchingClassRule('openai', 'text-moderation-latest'));
        $this->assertNotNull(ModelDiscoveryIgnoreList::matchingClassRule('openai', 'gpt-4o-realtime-preview'));
        $this->assertNotNull(ModelDiscoveryIgnoreList::matchingClassRule('mistral', 'mistral-moderation-latest'));
        $this->assertNull(ModelDiscoveryIgnoreList::matchingClassRule('openai', 'text-embedding-3-large'));
        $this->assertNull(ModelDiscoveryIgnoreList::matchingClassRule('openai', 'whisper-1'));
        $this->assertNull(ModelDiscoveryIgnoreList::matchingClassRule('openai', 'gpt-image-1'));
    }

    public function testContainsIncludesClassRules(): void
    {
        $this->assertTrue(ModelDiscoveryIgnoreList::contains('openai', 'ft:somewhere'));
        $this->assertTrue(ModelDiscoveryIgnoreList::contains('OpenAI', 'omni-moderation-latest'));
    }

    public function testClassRulesAreExactlyTheFourDecided(): void
    {
        $this->assertCount(4, ModelDiscoveryIgnoreList::CLASS_RULES);
        $values = array_map(
            static fn (array $r): string => $r['provider'].':'.$r['match'].':'.$r['value'],
            ModelDiscoveryIgnoreList::CLASS_RULES,
        );
        sort($values);
        $this->assertSame([
            'mistral:contains:moderation',
            'openai:contains:moderation',
            'openai:contains:realtime',
            'openai:prefix:ft:',
        ], $values);
    }
}
