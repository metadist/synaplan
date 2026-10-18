<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Entity\Message;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Classification\SlashCommandRoute;
use App\Service\Message\Classification\SlashOutcome;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SlashCommandRouteTest extends TestCase
{
    public function testPlainTextIsNoCommand(): void
    {
        self::assertEquals(new SlashOutcome(), $this->route()->resolve($this->message('Hello there')));
    }

    public function testABareCommandAnswersWithItsUsageHint(): void
    {
        $answer = $this->route()->resolve($this->message('/pic'))->answer;

        self::assertSame(['general', true, 'pic', true], [$answer['topic'] ?? null, $answer['slash_hint'] ?? null, $answer['slash_command'] ?? null, $answer['skip_sorting'] ?? null]);
    }

    public function testAToolCommandRoutesToItsTool(): void
    {
        $answer = $this->route()->resolve($this->message('/vid a drone over the alps'))->answer;

        self::assertSame(['tools:vid', 'image_generation'], [$answer['topic'] ?? null, $answer['intent'] ?? null]);
    }

    public function testSearchAndDocsStripTheCommandAndBecomeOverlays(): void
    {
        $search = $this->message('/search php release');
        $docs = $this->message('/docs vacation policy');

        self::assertEquals(new SlashOutcome(null, true, false), $this->route()->resolve($search));
        self::assertEquals(new SlashOutcome(null, false, true), $this->route()->resolve($docs));
        self::assertSame(['php release', 'vacation policy'], [$search->getText(), $docs->getText()]);
        self::assertSame(
            ['topic' => 'support-desk', 'force_web_search' => true],
            $this->route()->withOverlays(['topic' => 'support-desk'], new SlashOutcome(null, true, false)),
        );
        self::assertSame(
            ['topic' => 'support-desk', 'intent' => 'rag_query', 'slash_docs' => true],
            $this->route()->withOverlays(['topic' => 'support-desk'], new SlashOutcome(null, false, true)),
        );
    }

    public function testDocsWithoutAPinQueriesTheKnowledgeBase(): void
    {
        $route = $this->route()->docsRoute($this->message('vacation policy', 'NN'));

        self::assertSame(['general', 'en', 'rag_query', true, true], [$route['topic'], $route['language'], $route['intent'], $route['skip_sorting'], $route['slash_docs']]);
    }

    private function route(): SlashCommandRoute
    {
        return new SlashCommandRoute(new TopicIntentRule(new SystemCapabilityRegistry()), new NullLogger());
    }

    private function message(string $text, string $language = 'en'): Message
    {
        $message = new Message();
        $message->setText($text);
        $message->setLanguage($language);

        return $message;
    }
}
