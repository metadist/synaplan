<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\AI\ToolCalling\ToolCallingCapability;
use App\Entity\Message;
use App\Service\Message\Classification\RoutingDeferral;
use App\Service\Message\Classification\SelfAwareQuestion;
use App\Service\Message\Routing\NativeToolRoutingConfig;
use App\Service\ModelConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RoutingDeferralTest extends TestCase
{
    public function testAnOrdinaryTurnIsDeferredToTheAnsweringCall(): void
    {
        $route = $this->deferral()->defer($this->message('Explain photosynthesis to me'), 'Explain photosynthesis to me', null, true, false);

        self::assertNotNull($route);
        self::assertSame(
            ['general', 'chat', 'en', 'native_tool_calling', true, true, null],
            [$route['topic'], $route['intent'], $route['language'], $route['source'], $route['skip_sorting'], $route['defer_routing_to_chat'], $route['web_search']],
        );
        self::assertArrayNotHasKey('force_web_search', $route);
    }

    public function testAnUnknownLanguageStillDefersAndAForcedSearchSurvives(): void
    {
        $route = $this->deferral()->defer($this->message('ok', 'NN'), 'ok', null, true, true);

        self::assertSame(['en', true], [$route['language'] ?? null, $route['force_web_search'] ?? null]);
    }

    public function testWhatTheCallerOrTheAccountRulesOutGoesToTheSorter(): void
    {
        $message = $this->message('Explain photosynthesis to me');

        self::assertNull($this->deferral()->defer($message, 'Explain photosynthesis to me', null, false, false), 'not allowed');
        self::assertNull($this->deferral()->defer($message, 'Explain photosynthesis to me', 42, true, false), 'a picked model');
        self::assertNull($this->deferral(enabled: false)->defer($message, 'Explain photosynthesis to me', null, true, false), 'switched off');
        self::assertNull($this->deferral(provider: 'openai')->defer($message, 'Explain photosynthesis to me', null, true, false), 'no native routing');
        self::assertNull($this->deferral(provider: '')->defer($message, 'Explain photosynthesis to me', null, true, false), 'no chat model');
    }

    private function deferral(bool $enabled = true, string $provider = 'anthropic'): RoutingDeferral
    {
        $config = $this->createStub(NativeToolRoutingConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getDefaultProvider')->willReturn($provider);

        return new RoutingDeferral($config, $models, new ToolCallingCapability(), new SelfAwareQuestion(), new NullLogger());
    }

    private function message(string $text, string $language = 'en'): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getUserId')->willReturn(10);
        $message->method('getLanguage')->willReturn($language);

        return $message;
    }
}
