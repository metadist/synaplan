<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\WebSearchTopicPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks down the contract of the project-wide "rather search than not"
 * routing policy.
 *
 * The four decision rules are exhaustive — every combination of topic
 * (non-search vs. search-friendly) × prompt flag (true / false / null)
 * is covered by the data provider so any drift in the policy is caught
 * by CI before it hits production.
 */
final class WebSearchTopicPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?bool, 2: bool, 3: string}>
     */
    public static function shouldSearchProvider(): iterable
    {
        // Rule 1: explicit opt-in is absolute, beats the NON_WEB_SEARCH gate.
        yield 'opt_in_on_chat_topic_searches' => ['general', true, true, 'rule 1: explicit opt-in'];
        yield 'opt_in_on_media_topic_searches' => ['mediamaker', true, true, 'rule 1: opt-in overrides NON_WEB_SEARCH gate'];

        // Rule 2: NON_WEB_SEARCH topics suppress search when no opt-in is set.
        yield 'mediamaker_no_opinion' => ['mediamaker', null, false, 'rule 2: media topic without opt-in'];
        yield 'officemaker_no_opinion' => ['officemaker', null, false, 'rule 2: document topic'];

        // Rule 3: explicit opt-out suppresses search on chat-friendly topics.
        yield 'opt_out_on_general' => ['general', false, false, 'rule 3: explicit opt-out'];
        yield 'opt_out_on_custom' => ['my-custom-topic', false, false, 'rule 3: explicit opt-out on user prompt'];

        // Rule 4: project default — chat-friendly topic, no opinion → search.
        yield 'general_no_opinion' => ['general', null, true, 'rule 4: project default'];
        yield 'chat_no_opinion' => ['chat', null, true, 'rule 4: project default'];
        yield 'custom_topic_no_opinion' => ['my-custom-topic', null, true, 'rule 4: custom prompt without opinion'];

        // Edge cases.
        yield 'null_topic_no_opinion' => [null, null, true, 'null topic falls through to default'];
        yield 'empty_topic_no_opinion' => ['', null, true, 'empty topic falls through to default'];
        yield 'null_topic_opt_out' => [null, false, false, 'opt-out wins even for unknown topic'];
    }

    #[DataProvider('shouldSearchProvider')]
    public function testShouldSearchAppliesPolicy(?string $topic, ?bool $promptToolInternet, bool $expected, string $reason): void
    {
        self::assertSame(
            $expected,
            WebSearchTopicPolicy::shouldSearch($topic, $promptToolInternet),
            sprintf('Topic=%s, tool_internet=%s, %s', $topic ?? 'NULL', var_export($promptToolInternet, true), $reason),
        );
    }

    public function testIsNonWebSearchTopicCoversMediaAndDocumentTopics(): void
    {
        self::assertTrue(WebSearchTopicPolicy::isNonWebSearchTopic('mediamaker'));
        self::assertTrue(WebSearchTopicPolicy::isNonWebSearchTopic('officemaker'));
        self::assertTrue(WebSearchTopicPolicy::isNonWebSearchTopic('text2pic'));
        self::assertTrue(WebSearchTopicPolicy::isNonWebSearchTopic('text2vid'));
        self::assertTrue(WebSearchTopicPolicy::isNonWebSearchTopic('text2sound'));
        self::assertTrue(WebSearchTopicPolicy::isNonWebSearchTopic('text2doc'));

        // Chat-friendly topics
        self::assertFalse(WebSearchTopicPolicy::isNonWebSearchTopic('general'));
        self::assertFalse(WebSearchTopicPolicy::isNonWebSearchTopic('chat'));
        self::assertFalse(WebSearchTopicPolicy::isNonWebSearchTopic('analyzefile'));

        // Edge cases
        self::assertFalse(WebSearchTopicPolicy::isNonWebSearchTopic(null));
        self::assertFalse(WebSearchTopicPolicy::isNonWebSearchTopic(''));
    }
}
