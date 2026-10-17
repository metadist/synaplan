<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Capability;

use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Capability\TopicIntent;
use App\Service\Message\Capability\TopicIntentRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TopicIntentRuleTest extends TestCase
{
    private TopicIntentRule $rule;

    protected function setUp(): void
    {
        $this->rule = new TopicIntentRule(new SystemCapabilityRegistry());
    }

    /**
     * One row per tag line of the D2 table (S03 §2): the tags, then the
     * topic on the message => the [topic, intent] it routes to.
     *
     * @return iterable<string, array{0: list<?string>, 1: array<string, array{0: string, 1: string}>}>
     */
    public static function d2Rows(): iterable
    {
        yield 'text2pic / text2vid / text2sound: mediamaker' => [
            ['text2pic', 'text2vid', 'text2sound'],
            ['' => ['mediamaker', 'image_generation'], 'legal-review' => ['mediamaker', 'image_generation']],
        ];
        yield 'pic2text / analyze / vision: general' => [
            ['pic2text', 'analyze', 'vision'],
            ['' => ['general', 'chat'], 'mediamaker' => ['general', 'chat']],
        ];
        yield 'document / officemaker / text2doc: officemaker' => [
            ['document', 'officemaker', 'text2doc'],
            ['' => ['officemaker', 'document_generation'], 'mediamaker' => ['officemaker', 'document_generation']],
        ];
        yield 'chat, vectorize, unknown: the topic it is given, else general' => [
            ['chat', 'vectorize', 'rerank', null],
            [
                'legal-review' => ['legal-review', 'chat'],
                'mediamaker' => ['mediamaker', 'image_generation'],
                'tools:pic' => ['tools:pic', 'image_generation'],
                '' => ['general', 'chat'],
                'unknown' => ['general', 'chat'],
                'UNKNOWN' => ['general', 'chat'],
                'Unknown' => ['general', 'chat'],
                'CHAT' => ['general', 'chat'],
                'CONTINUE' => ['general', 'chat'],
            ],
        ];
    }

    /**
     * @param list<?string>                              $tags
     * @param array<string, array{0: string, 1: string}> $routes
     */
    #[DataProvider('d2Rows')]
    public function testAPickedModelRoutesByItsLineOfTheD2Table(array $tags, array $routes): void
    {
        foreach ($tags as $tag) {
            foreach ($routes as $topic => [$expectedTopic, $expectedIntent]) {
                self::assertEquals(
                    new TopicIntent($expectedTopic, $expectedIntent),
                    $this->rule->forModel($tag, (string) $topic),
                    sprintf('%s on "%s"', $tag ?? 'no tag', $topic),
                );
            }
        }
    }

    public function testAUsersPromptNamedLikeAPlaceholderKeepsItsTopic(): void
    {
        self::assertEquals(new TopicIntent('chat', 'chat'), $this->rule->forModel('chat', 'chat'));
    }

    public function testATagMatchesInAnyCase(): void
    {
        self::assertEquals(new TopicIntent('mediamaker', 'image_generation'), $this->rule->forModel('TEXT2PIC', 'legal-review'));
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
