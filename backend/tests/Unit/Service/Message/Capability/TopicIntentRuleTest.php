<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Capability;

use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Capability\TopicIntent;
use App\Service\Message\Capability\TopicIntentRule;
use PHPUnit\Framework\TestCase;

final class TopicIntentRuleTest extends TestCase
{
    private TopicIntentRule $rule;

    protected function setUp(): void
    {
        $this->rule = new TopicIntentRule(new SystemCapabilityRegistry());
    }

    public function testASystemTopicRoutesToTheIntentTheRegistryNames(): void
    {
        self::assertSame(
            ['general' => 'chat', 'mediamaker' => 'image_generation', 'officemaker' => 'document_generation', 'docsummary' => 'chat'],
            $this->intents(['general', 'mediamaker', 'officemaker', 'docsummary']),
        );
    }

    public function testAnAliasRoutesLikeTheCapabilityItStandsFor(): void
    {
        self::assertSame(
            [
                'text2pic' => 'image_generation', 'text2vid' => 'image_generation', 'text2sound' => 'image_generation',
                'tools:pic' => 'image_generation', 'tools:vid' => 'image_generation', 'tools:tts' => 'image_generation',
                'pic2text' => 'file_analysis', 'analyze' => 'file_analysis', 'analyzefile' => 'file_analysis',
                'chat' => 'chat',
            ],
            $this->intents(['text2pic', 'text2vid', 'text2sound', 'tools:pic', 'tools:vid', 'tools:tts', 'pic2text', 'analyze', 'analyzefile', 'chat']),
        );
    }

    public function testAnyOtherTopicIsATaskPromptAndAnswersAsChat(): void
    {
        self::assertSame(['legal-review' => 'chat', 'UNKNOWN' => 'chat'], $this->intents(['legal-review', 'UNKNOWN']));
    }

    public function testAModelOverrideDropsTheMessageTopicForAChatModel(): void
    {
        self::assertEquals(new TopicIntent('mediamaker', 'image_generation'), $this->rule->forModelOverride('text2pic', 'legal-review'));
        self::assertEquals(new TopicIntent('general', 'chat'), $this->rule->forModelOverride('chat', 'legal-review'));
        self::assertEquals(new TopicIntent('legal-review', 'chat'), $this->rule->forModelOverride('rerank', 'legal-review'));
    }

    public function testAnAgainKeepsTheStoredTopicForAChatModel(): void
    {
        self::assertEquals(new TopicIntent('officemaker', 'document_generation'), $this->rule->forAgain('document', 'general'));
        self::assertEquals(new TopicIntent('legal-review', 'chat'), $this->rule->forAgain('chat', 'legal-review'));
        self::assertEquals(new TopicIntent('chat', 'chat'), $this->rule->forAgain(null, 'UNKNOWN'));
    }

    /**
     * @param list<string> $topics
     *
     * @return array<string, string>
     */
    private function intents(array $topics): array
    {
        return array_combine($topics, array_map($this->rule->intentFor(...), $topics));
    }
}
