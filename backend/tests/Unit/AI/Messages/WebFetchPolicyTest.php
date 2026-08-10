<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Tools\AnthropicServerTools;
use App\AI\Messages\Tools\WebFetchPolicy;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use PHPUnit\Framework\TestCase;

final class WebFetchPolicyTest extends TestCase
{
    private WebFetchPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new WebFetchPolicy();
    }

    public function testAnthropicAutoInjectsWhenClientOmittedTheTool(): void
    {
        $result = $this->policy->apply(
            ['messages' => [], 'max_tokens' => 100],
            'anthropic',
            MessagesGatewayConfig::WEB_FETCH_AUTO,
            null,
        );

        self::assertSame(WebFetchPolicy::HANDLING_PASSTHROUGH, $result['handling']);
        self::assertTrue($result['mutated']);
        self::assertTrue($this->policy->hasServerWebFetch($result['body']));
        self::assertSame(WebFetchPolicy::BETA_FEATURE, $result['anthropic_beta']);
        self::assertSame(WebFetchPolicy::DEFAULT_DECLARATION, $result['body']['tools'][0]);
    }

    public function testAnthropicKeepsAnExistingDeclarationAndAddsBeta(): void
    {
        $declared = ['type' => 'web_fetch_20250910', 'name' => 'web_fetch', 'max_uses' => 3];
        $result = $this->policy->apply(
            ['tools' => [$declared]],
            'anthropic',
            MessagesGatewayConfig::WEB_FETCH_PASSTHROUGH,
            'fine-grained-tool-streaming-2025-05-14',
        );

        self::assertSame(WebFetchPolicy::HANDLING_PASSTHROUGH, $result['handling']);
        self::assertFalse($result['mutated']);
        self::assertSame([$declared], $result['body']['tools']);
        self::assertSame(
            'fine-grained-tool-streaming-2025-05-14,'.WebFetchPolicy::BETA_FEATURE,
            $result['anthropic_beta'],
        );
    }

    public function testDoesNotDuplicateBetaFeature(): void
    {
        $result = $this->policy->apply(
            ['tools' => [WebFetchPolicy::DEFAULT_DECLARATION]],
            'anthropic',
            MessagesGatewayConfig::WEB_FETCH_AUTO,
            WebFetchPolicy::BETA_FEATURE,
        );

        self::assertSame(WebFetchPolicy::BETA_FEATURE, $result['anthropic_beta']);
    }

    public function testOpenAiAliasStripsServerWebFetch(): void
    {
        $result = $this->policy->apply(
            ['tools' => [WebFetchPolicy::DEFAULT_DECLARATION, ['name' => 'get_weather', 'input_schema' => ['type' => 'object']]]],
            'openai',
            MessagesGatewayConfig::WEB_FETCH_AUTO,
            null,
        );

        self::assertSame(WebFetchPolicy::HANDLING_OFF, $result['handling']);
        self::assertTrue($result['mutated']);
        self::assertFalse($this->policy->hasServerWebFetch($result['body']));
        self::assertCount(1, $result['body']['tools']);
    }

    public function testOffModeStripsEvenOnAnthropic(): void
    {
        $result = $this->policy->apply(
            ['tools' => [WebFetchPolicy::DEFAULT_DECLARATION]],
            'anthropic',
            MessagesGatewayConfig::WEB_FETCH_OFF,
            null,
        );

        self::assertSame(WebFetchPolicy::HANDLING_OFF, $result['handling']);
        self::assertTrue($result['mutated']);
        self::assertFalse($this->policy->hasServerWebFetch($result['body']));
    }

    public function testClientOwnedWebFetchIsLeftAlone(): void
    {
        $clientTool = [
            'name' => AnthropicServerTools::WEB_FETCH_NAME,
            'description' => 'Fetch a URL',
            'input_schema' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']]],
        ];
        $result = $this->policy->apply(
            ['tools' => [$clientTool]],
            'anthropic',
            MessagesGatewayConfig::WEB_FETCH_AUTO,
            null,
        );

        self::assertSame(WebFetchPolicy::HANDLING_NONE, $result['handling']);
        self::assertFalse($result['mutated']);
        self::assertSame([$clientTool], $result['body']['tools']);
    }

    public function testIsWebFetchDetectsVersionedTypes(): void
    {
        self::assertTrue(AnthropicServerTools::isWebFetch(['type' => 'web_fetch_20250910', 'name' => 'web_fetch']));
        self::assertTrue(AnthropicServerTools::isWebFetch(['type' => 'web_fetch_20260318', 'name' => 'web_fetch']));
        self::assertFalse(AnthropicServerTools::isWebFetch([
            'name' => 'web_fetch',
            'input_schema' => ['type' => 'object'],
        ]));
    }
}
