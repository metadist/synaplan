<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Entity\Message;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Classification\EmbeddingRoute;
use App\Service\Message\Classification\SelfAwareQuestion;
use App\Service\Message\Routing\EmbeddingRouterConfig;
use App\Service\Message\Routing\EmbeddingRouterMatch;
use App\Service\Message\Routing\EmbeddingRouterService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EmbeddingRouteTest extends TestCase
{
    public function testAConfidentMatchInAKnownLanguageSkipsTheSorter(): void
    {
        $verdict = $this->route(new EmbeddingRouterMatch('mediamaker', 0.91, [['topic' => 'general', 'score' => 0.42]]))
            ->match($this->message('draw a red fox in the snow'), 'draw a red fox in the snow', null, false, true);
        $route = $verdict->classification;

        self::assertNotNull($route);
        self::assertFalse($verdict->declinedForLanguage);
        self::assertSame(
            ['mediamaker', 'image_generation', 'en', 'embedding_router', true, null, 0.91, ['general (0.420)'], true],
            [$route['topic'], $route['intent'], $route['language'], $route['source'], $route['skip_sorting'], $route['web_search'], $route['routing_confidence'], $route['routing_discarded_alternatives'], $route['force_web_search'] ?? null],
        );
    }

    public function testAMatchInAnUnknownLanguageIsDeclinedForTheSorter(): void
    {
        $verdict = $this->route(new EmbeddingRouterMatch('general', 0.95))->match($this->message('ok', 'NN'), 'ok', null, false, false);

        self::assertNull($verdict->classification);
        self::assertTrue($verdict->declinedForLanguage, 'The deferral must not take what the sorter has to decide.');
    }

    public function testAWeakMatchLeavesTheTurnToTheNextStep(): void
    {
        $verdict = $this->route(new EmbeddingRouterMatch('officemaker', 0.5))->match($this->message('write me a letter'), 'write me a letter', null, false, false);

        self::assertEquals([null, false], [$verdict->classification, $verdict->declinedForLanguage]);
    }

    public function testTheRouterIsNotAskedWhenTheTurnIsNotItsToDecide(): void
    {
        self::assertNull($this->route(null, enabled: false)->match($this->message('draw a fox'), 'draw a fox', null, false, false)->classification);
        self::assertNull($this->route(null)->match($this->message('read it aloud'), 'read it aloud', null, true, false)->classification);
        self::assertNull($this->route(null)->match($this->message('draw a fox'), 'draw a fox', 42, false, false)->classification);
        self::assertNull($this->route(null)->match($this->message(''), '', null, false, false)->classification);
    }

    private function route(?EmbeddingRouterMatch $match, bool $enabled = true): EmbeddingRoute
    {
        $router = $this->createMock(EmbeddingRouterService::class);
        $router->expects(null === $match ? self::never() : self::once())->method('findClosestAnchor')->willReturn($match);
        $config = $this->createStub(EmbeddingRouterConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getConfidenceThreshold')->willReturn(0.8);

        return new EmbeddingRoute($router, $config, new TopicIntentRule(new SystemCapabilityRegistry()), new SelfAwareQuestion(), new NullLogger());
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
