<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ModelDiscoveryIgnoreList;
use PHPUnit\Framework\TestCase;

final class ModelDiscoveryIgnoreListTest extends TestCase
{
    public function testStartsEmpty(): void
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
}
