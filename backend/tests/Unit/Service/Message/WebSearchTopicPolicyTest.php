<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\WebSearchTopicPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks down the contract of the hybrid web-search routing policy.
 *
 * The decision rules are exhaustive — every relevant combination of explicit
 * user request × topic (non-search vs. search-friendly) × prompt flag
 * (true / false / null) × classifier BWEBSEARCH vote (true / false / null) ×
 * message triviality is covered so any drift in the policy is caught by CI.
 */
final class WebSearchTopicPolicyTest extends TestCase
{
    /**
     * A non-trivial, search-worthy message used by the rule cases that are not
     * specifically exercising the triviality veto.
     */
    private const NON_TRIVIAL = 'tell me about the latest developments in fusion energy research';

    /**
     * @return iterable<string, array{0: ?string, 1: bool, 2: ?bool, 3: ?bool, 4: ?string, 5: bool, 6: string}>
     */
    public static function shouldSearchProvider(): iterable
    {
        // Rule 1: explicit prompt opt-out is a HARD disable — beats the
        // per-message user request and a yes-vote.
        yield 'opt_out_beats_user_request' => ['general', true, false, true, self::NON_TRIVIAL, false, 'rule 1: hard disable beats explicit user request'];
        yield 'opt_out_on_general' => ['general', false, false, true, self::NON_TRIVIAL, false, 'rule 1: explicit opt-out beats yes-vote'];
        yield 'opt_out_on_custom' => ['my-custom-topic', false, false, true, self::NON_TRIVIAL, false, 'rule 1: explicit opt-out on user prompt'];

        // Rule 2: explicit per-message user request forces search — beats the
        // no-vote, the triviality veto and the NON_WEB_SEARCH topic gate.
        yield 'user_request_beats_no_vote' => ['general', true, null, false, 'hey', true, 'rule 2: explicit request beats no-vote and triviality'];
        yield 'user_request_on_media_topic' => ['mediamaker', true, null, null, 'a cat', true, 'rule 2: explicit request beats NON_WEB_SEARCH gate'];

        // Rule 3: explicit opt-in is absolute, beats the NON_WEB_SEARCH gate
        // and overrides a "no" vote from the model.
        yield 'opt_in_on_chat_topic_searches' => ['general', false, true, false, self::NON_TRIVIAL, true, 'rule 3: explicit opt-in beats no-vote'];
        yield 'opt_in_on_media_topic_searches' => ['mediamaker', false, true, null, self::NON_TRIVIAL, true, 'rule 3: opt-in overrides NON_WEB_SEARCH gate'];

        // Rule 4: NON_WEB_SEARCH topics suppress search when no opt-in is set,
        // even if the model voted to search.
        yield 'mediamaker_no_opinion' => ['mediamaker', false, null, true, self::NON_TRIVIAL, false, 'rule 4: media topic without opt-in'];
        yield 'officemaker_no_opinion' => ['officemaker', false, null, true, self::NON_TRIVIAL, false, 'rule 4: document topic'];

        // Rule 5: no explicit flag → trust the classifier's BWEBSEARCH vote
        // for non-trivial messages.
        yield 'general_vote_yes' => ['general', false, null, true, self::NON_TRIVIAL, true, 'rule 5: model voted to search'];
        yield 'general_vote_no' => ['general', false, null, false, self::NON_TRIVIAL, false, 'rule 5: model voted no search'];
        yield 'general_no_vote' => ['general', false, null, null, self::NON_TRIVIAL, false, 'rule 5: no vote (fast-path) → no search'];
        yield 'custom_topic_vote_yes' => ['my-custom-topic', false, null, true, self::NON_TRIVIAL, true, 'rule 5: custom prompt, model voted yes'];
        yield 'custom_topic_vote_no' => ['my-custom-topic', false, null, false, self::NON_TRIVIAL, false, 'rule 5: custom prompt, model voted no'];

        // Rule 5 veto: an over-eager yes-vote on a trivial chat is suppressed.
        yield 'trivial_greeting_vote_yes' => ['general', false, null, true, 'Hey, wie gehts?', false, 'rule 5 veto: greeting suppresses yes-vote'];
        yield 'trivial_thanks_vote_yes' => ['general', false, null, true, 'thanks a lot!', false, 'rule 5 veto: thanks suppresses yes-vote'];
        yield 'trivial_short_noise_vote_yes' => ['general', false, null, true, 'lol ok', false, 'rule 5 veto: short noise suppresses yes-vote'];
        // …but a trivial message never blocks an explicit opt-in/request.
        yield 'trivial_but_opt_in_searches' => ['general', false, true, true, 'hello', true, 'opt-in beats triviality veto'];
        yield 'trivial_but_user_request_searches' => ['general', true, null, true, 'hello', true, 'user request beats triviality veto'];

        // Edge cases.
        yield 'null_topic_vote_yes' => [null, false, null, true, self::NON_TRIVIAL, true, 'null topic with yes-vote searches'];
        yield 'null_topic_no_vote' => [null, false, null, null, self::NON_TRIVIAL, false, 'null topic without vote does not search'];
        yield 'null_topic_opt_out' => [null, false, false, true, self::NON_TRIVIAL, false, 'opt-out wins even for unknown topic'];
    }

    #[DataProvider('shouldSearchProvider')]
    public function testShouldSearchAppliesPolicy(?string $topic, bool $userRequestedSearch, ?bool $promptToolInternet, ?bool $classifierVote, ?string $messageText, bool $expected, string $reason): void
    {
        self::assertSame(
            $expected,
            WebSearchTopicPolicy::shouldSearch($topic, $userRequestedSearch, $promptToolInternet, $classifierVote, $messageText),
            sprintf(
                'Topic=%s, userRequest=%s, tool_internet=%s, vote=%s, text=%s, %s',
                $topic ?? 'NULL',
                var_export($userRequestedSearch, true),
                var_export($promptToolInternet, true),
                var_export($classifierVote, true),
                var_export($messageText, true),
                $reason,
            ),
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

    /**
     * @return iterable<string, array{0: ?string, 1: bool}>
     */
    public static function trivialConversationProvider(): iterable
    {
        // Trivial: greetings / smalltalk / acknowledgements (any length).
        yield 'german_greeting_question' => ['Hey, wie gehts?', true];
        yield 'german_hello' => ['Hallo!', true];
        yield 'english_how_are_you' => ['Hi, how are you?', true];
        yield 'english_thanks' => ['thank you so much', true];
        yield 'spanish_greeting' => ['Hola, qué tal', true];
        yield 'french_greeting' => ['Bonjour, ça va', true];
        yield 'turkish_greeting' => ['Merhaba, nasılsın', true];
        yield 'good_morning' => ['Good morning', true];

        // Trivial: ultra-short, question-less noise.
        yield 'short_noise' => ['lol ok', true];
        yield 'single_word' => ['danke', true];

        // Not trivial: actuality signals make a short message search-worthy.
        yield 'weather_query' => ['weather tomorrow', false];
        yield 'price_query' => ['Bitcoin Preis', false];
        yield 'news_query' => ['latest news', false];
        yield 'year_anchor' => ['olympics 2026', false];

        // Not trivial: real questions / informative requests.
        yield 'capital_question' => ['What is the capital of France?', false];
        yield 'short_real_question' => ['wann kommt gta', false];
        yield 'coding_request' => ['write a python function to sort a list', false];

        // Edge cases.
        yield 'empty' => ['', false];
        yield 'null' => [null, false];
    }

    #[DataProvider('trivialConversationProvider')]
    public function testIsTrivialConversational(?string $text, bool $expected): void
    {
        self::assertSame(
            $expected,
            WebSearchTopicPolicy::isTrivialConversational($text),
            sprintf('text=%s', var_export($text, true)),
        );
    }
}
