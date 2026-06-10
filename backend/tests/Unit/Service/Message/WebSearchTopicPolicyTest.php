<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\WebSearchTopicPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks down the contract of the "trust the model" web-search routing policy.
 *
 * The four decision rules are exhaustive — every combination of topic
 * (non-search vs. search-friendly) × prompt flag (true / false / null) ×
 * classifier BWEBSEARCH vote (true / false / null) that matters is covered
 * by the data provider so any drift in the policy is caught by CI.
 */
final class WebSearchTopicPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?bool, 2: ?bool, 3: bool, 4: string}>
     */
    public static function shouldSearchProvider(): iterable
    {
        // Rule 1: explicit opt-in is absolute, beats the NON_WEB_SEARCH gate
        // and overrides a "no" vote from the model.
        yield 'opt_in_on_chat_topic_searches' => ['general', true, false, true, 'rule 1: explicit opt-in beats no-vote'];
        yield 'opt_in_on_media_topic_searches' => ['mediamaker', true, null, true, 'rule 1: opt-in overrides NON_WEB_SEARCH gate'];

        // Rule 2: NON_WEB_SEARCH topics suppress search when no opt-in is set,
        // even if the model voted to search.
        yield 'mediamaker_no_opinion' => ['mediamaker', null, true, false, 'rule 2: media topic without opt-in'];
        yield 'officemaker_no_opinion' => ['officemaker', null, true, false, 'rule 2: document topic'];

        // Rule 3: explicit opt-out suppresses search even when the model voted yes.
        yield 'opt_out_on_general' => ['general', false, true, false, 'rule 3: explicit opt-out beats yes-vote'];
        yield 'opt_out_on_custom' => ['my-custom-topic', false, true, false, 'rule 3: explicit opt-out on user prompt'];

        // Rule 4: no explicit flag → trust the classifier's BWEBSEARCH vote.
        yield 'general_vote_yes' => ['general', null, true, true, 'rule 4: model voted to search'];
        yield 'general_vote_no' => ['general', null, false, false, 'rule 4: model voted no search'];
        yield 'general_no_vote' => ['general', null, null, false, 'rule 4: no vote (fast-path) → no search'];
        yield 'custom_topic_vote_yes' => ['my-custom-topic', null, true, true, 'rule 4: custom prompt, model voted yes'];
        yield 'custom_topic_vote_no' => ['my-custom-topic', null, false, false, 'rule 4: custom prompt, model voted no'];

        // Edge cases.
        yield 'null_topic_vote_yes' => [null, null, true, true, 'null topic with yes-vote searches'];
        yield 'null_topic_no_vote' => [null, null, null, false, 'null topic without vote does not search'];
        yield 'null_topic_opt_out' => [null, false, true, false, 'opt-out wins even for unknown topic'];
    }

    #[DataProvider('shouldSearchProvider')]
    public function testShouldSearchAppliesPolicy(?string $topic, ?bool $promptToolInternet, ?bool $classifierVote, bool $expected, string $reason): void
    {
        self::assertSame(
            $expected,
            WebSearchTopicPolicy::shouldSearch($topic, $promptToolInternet, $classifierVote),
            sprintf('Topic=%s, tool_internet=%s, vote=%s, %s', $topic ?? 'NULL', var_export($promptToolInternet, true), var_export($classifierVote, true), $reason),
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
