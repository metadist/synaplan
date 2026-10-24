<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug\WebSearch;

use App\Plug\WebSearch\WebSearchHosting;
use PHPUnit\Framework\TestCase;

final class WebSearchHostingTest extends TestCase
{
    public function testOnlySearxngIsSelfHosted(): void
    {
        $this->assertTrue(WebSearchHosting::selfHosted('searxng'));
        $this->assertTrue(WebSearchHosting::selfHosted('SearXNG'));
        foreach (['brave', 'tavily', 'exa', 'firecrawl', 'perplexity'] as $provider) {
            $this->assertFalse(WebSearchHosting::selfHosted($provider), $provider);
        }
        $this->assertFalse(WebSearchHosting::selfHosted('unknown'));
    }
}
