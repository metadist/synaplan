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
 * user request (toggle or text) × topic (non-search vs. search-friendly) ×
 * prompt flag (true / false / null) × classifier BWEBSEARCH vote (true /
 * false / null) × message triviality is covered so any drift in the policy
 * is caught by CI.
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

        // Explicit request written in the message itself — same as the toggle.
        yield 'text_request_de' => ['general', false, null, false, 'Such im Internet nach den Öffnungszeiten vom Städel', true, 'rule 2: "such im Internet" is an explicit request'];
        yield 'text_request_en' => ['general', false, null, null, 'search the web for cheap flights to Rome', true, 'rule 2: "search the web" is an explicit request'];
        yield 'text_request_beaten_by_opt_out' => ['general', false, false, null, 'google mal das Wetter', false, 'rule 1: hard disable beats a text request'];

        // `tool_internet=true` only ALLOWS search: it never forces one.
        yield 'allow_flag_greeting' => ['general', false, true, null, 'Hi, wie gehts?', false, 'allow flag does not search a greeting'];
        yield 'allow_flag_vote_no' => ['general', false, true, false, 'Wie lang ist die Chinesische Mauer?', false, 'allow flag follows a no-vote'];
        yield 'allow_flag_vote_yes' => ['general', false, true, true, 'Wie steht der Dollar zum Euro?', true, 'allow flag follows a yes-vote'];
        yield 'allow_flag_media_topic' => ['mediamaker', false, true, true, self::NON_TRIVIAL, false, 'allow flag does not lift the media gate'];

        // Rule 3: NON_WEB_SEARCH topics suppress a vote-triggered search.
        yield 'mediamaker_no_opinion' => ['mediamaker', false, null, true, self::NON_TRIVIAL, false, 'rule 3: media topic'];
        yield 'officemaker_no_opinion' => ['officemaker', false, null, true, self::NON_TRIVIAL, false, 'rule 3: document topic'];

        // Rule 4: trust the classifier's BWEBSEARCH vote for non-trivial messages.
        yield 'general_vote_yes' => ['general', false, null, true, self::NON_TRIVIAL, true, 'rule 4: model voted to search'];
        yield 'general_vote_no' => ['general', false, null, false, self::NON_TRIVIAL, false, 'rule 4: model voted no search'];
        yield 'general_no_vote' => ['general', false, null, null, self::NON_TRIVIAL, false, 'rule 4: no vote → no search'];
        yield 'custom_topic_vote_yes' => ['my-custom-topic', false, null, true, self::NON_TRIVIAL, true, 'rule 4: custom prompt, model voted yes'];
        yield 'custom_topic_vote_no' => ['my-custom-topic', false, null, false, self::NON_TRIVIAL, false, 'rule 4: custom prompt, model voted no'];
        yield 'greeting_plus_question_vote_yes' => ['general', false, null, true, 'Hi, wie steht der Dollar zum Euro?', true, 'rule 4: a greeting before a real question is not smalltalk'];

        // Rule 4 veto: an over-eager yes-vote on a trivial chat is suppressed.
        yield 'trivial_greeting_vote_yes' => ['general', false, null, true, 'Hey, wie gehts?', false, 'rule 4 veto: greeting suppresses yes-vote'];
        yield 'trivial_thanks_vote_yes' => ['general', false, null, true, 'thanks a lot!', false, 'rule 4 veto: thanks suppresses yes-vote'];
        yield 'trivial_short_noise_vote_yes' => ['general', false, null, true, 'lol ok', false, 'rule 4 veto: short noise suppresses yes-vote'];
        // …but a trivial message never blocks an explicit request.
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

    /**
     * Attachment-referring questions are NOT vetoed by the policy: the vote
     * is honoured, and MessageProcessor resolves the file's content so the
     * search query names the actual subject ("sony wh-1000xm6 price" instead
     * of "how much does this cost"). Only an unresolvable referent drops a
     * vote-only search — and that fallback lives in MessageProcessor, not
     * here. This test pins that shouldSearch() stays attachment-agnostic.
     */
    public function testShouldSearchHonoursVoteForAttachmentQuestions(): void
    {
        self::assertTrue(
            WebSearchTopicPolicy::shouldSearch('general', false, null, true, 'how much does this cost?'),
            'deictic question + yes-vote must search — the query is built from the attachment content',
        );
        self::assertTrue(
            WebSearchTopicPolicy::shouldSearch('general', false, null, true, 'what is that'),
            'even a pure deictic question searches when the model voted yes',
        );
        self::assertFalse(
            WebSearchTopicPolicy::shouldSearch('general', false, null, null, 'what is that'),
            'no vote still means no search',
        );
    }

    /**
     * @return iterable<string, array{0: ?string, 1: bool}>
     */
    public static function refersToAttachmentProvider(): iterable
    {
        // Deictic pronouns / demonstratives.
        yield 'what_is_that' => ['what is that?', true];
        yield 'what_is_it' => ['what is it?', true];
        yield 'german_was_ist_das' => ['Was ist das?', true];
        yield 'german_was_ist_es' => ['was ist es?', true];
        yield 'this_cost' => ['How much does this cost?', true];
        yield 'spanish_que_es_esto' => ['¿Qué es esto?', true];
        yield 'turkish_bu_ne' => ['bu ne?', true];
        yield 'french_cest_quoi_ca' => ["c'est quoi ça", true];

        // Image nouns and perception verbs.
        yield 'picture_noun' => ['what is in the picture', true];
        yield 'german_bild' => ['erkläre mir das Bild', true];
        yield 'perception_see' => ['what do you see here', true];

        // Document nouns — a contract PDF, an invoice, a report.
        yield 'contract_validity' => ['is the contract still valid', true];
        yield 'german_rechnung' => ['stimmt die Rechnung so', true];
        yield 'document_noun' => ['check the document for errors', true];

        // Audio/video nouns.
        yield 'recording_speaker' => ['who is speaking in the recording', true];
        yield 'german_lied' => ['wie heißt das Lied', true];
        yield 'video_noun' => ['where was the video filmed', true];

        // Attachment-only message: the attachment IS the message.
        yield 'empty' => ['', true];
        yield 'null' => [null, true];
        yield 'whitespace' => ['   ', true];

        // Self-contained subjects do NOT refer to the attachment — including
        // questions that happen to contain dummy "it" or the Spanish copula
        // "es". Those must not pull file content into the search query.
        yield 'self_contained_release' => ['GTA 6 release date', false];
        yield 'self_contained_price' => ['bitcoin price today', false];
        yield 'self_contained_weather' => ['weather tomorrow in Berlin', false];
        yield 'dummy_it_weather' => ['Is it raining in Berlin today?', false];
        yield 'spanish_copula_price' => ['¿Cuál es el precio del Bitcoin hoy?', false];
    }

    #[DataProvider('refersToAttachmentProvider')]
    public function testRefersToAttachment(?string $text, bool $expected): void
    {
        self::assertSame(
            $expected,
            WebSearchTopicPolicy::refersToAttachment($text),
            sprintf('text=%s', var_export($text, true)),
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
        yield 'greeting_with_time_of_day' => ['Guten Morgen!', true];

        // Not trivial: a greeting followed by a real question.
        yield 'greeting_then_fx_question' => ['Hi, wie steht der Dollar zum Euro?', false];
        yield 'thanks_then_question' => ['Danke! Und was ist die Hauptstadt von Kanada?', false];
        yield 'greeting_then_short_question' => ['Hi, Öffnungszeiten Städel?', false];
        yield 'greeting_then_short_remark' => ['Hallo, alles super', true];
        // Whole-word matching: "know" is not "now", "actually" is not "actual".
        yield 'know_is_not_now' => ['ok i know', true];
        yield 'actually_is_not_actual' => ['thanks actually', true];

        // Not trivial: actuality signals make a short message search-worthy.
        yield 'weather_query' => ['weather tomorrow', false];
        yield 'price_query' => ['Bitcoin Preis', false];
        yield 'news_query' => ['latest news', false];
        yield 'year_anchor' => ['olympics 2026', false];
        // Future-proof: any 20xx year is an actuality signal, not just the
        // current few — "olympics 2031" must not be vetoed as trivial noise.
        yield 'future_year_anchor' => ['olympics 2031', false];

        // Not trivial: real questions / informative requests.
        yield 'capital_question' => ['What is the capital of France?', false];
        yield 'short_real_question' => ['wann kommt gta', false];
        yield 'coding_request' => ['write a python function to sort a list', false];

        // Edge cases.
        yield 'empty' => ['', false];
        yield 'null' => [null, false];
    }

    /**
     * @return iterable<string, array{0: ?string, 1: bool}>
     */
    public static function explicitSearchRequestProvider(): iterable
    {
        yield 'de_such_im_internet' => ['Such im Internet nach dem Wetter', true];
        yield 'de_im_internet_nachschauen' => ['Kannst du im Internet nachschauen, wann Ikea öffnet?', true];
        yield 'de_google_mal' => ['google mal den Bitcoin-Kurs', true];
        yield 'de_recherchiere_online' => ['Recherchiere online, was das kostet', true];
        yield 'de_websuche' => ['Mach eine Websuche zu Tesla', true];
        yield 'en_search_the_web' => ['Search the web for the latest iPhone', true];
        yield 'en_look_it_up_online' => ['can you look it up online?', true];
        yield 'en_google_it' => ['just google it', true];
        yield 'es_busca_en_internet' => ['Busca en internet el horario del museo', true];
        yield 'fr_cherche_sur_internet' => ['Cherche sur internet les horaires', true];
        yield 'tr_internette_ara' => ['internette ara: hava durumu', true];
        yield 'en_greeting_then_request' => ['Hi, can you please search the web for train strikes?', true];
        yield 'en_do_a_web_search' => ['Do a web search on solar panel prices', true];
        yield 'de_bitte_suche_online' => ['Bitte suche online nach einem Rezept', true];
        yield 'de_kannst_du_googeln' => ['Kannst du das mal googeln?', true];
        yield 'de_websuche_colon' => ['Websuche: Öffnungszeiten Zoo Frankfurt', true];
        yield 'es_puedes_buscar' => ['¿Puedes buscar en internet el precio?', true];
        yield 'es_por_favor_busca' => ['Por favor, busca en internet el precio', true];
        yield 'fr_peux_tu_chercher' => ['Peux-tu chercher sur internet la météo ?', true];

        // Mentioning the web or Google is not a request to search.
        yield 'how_does_google_work' => ['How does Google search work?', false];
        yield 'en_why_people_search_the_web' => ['Why do people search the web for medical advice?', false];
        yield 'en_i_searched_the_web' => ['I tried to search the web but found nothing', false];
        yield 'de_wie_funktioniert_websuche' => ['Wie funktioniert eine Websuche?', false];
        yield 'de_ich_habe_im_internet_gesucht' => ['Ich habe im Internet gesucht, aber nichts gefunden', false];
        yield 'fr_pourquoi_chercher' => ['Pourquoi les gens cherchent sur internet ?', false];
        yield 'what_is_the_internet' => ['Was ist das Internet?', false];
        yield 'great_wall' => ['Wie lang ist die Chinesische Mauer?', false];
        yield 'greeting' => ['Hi, wie gehts?', false];
        yield 'empty' => ['', false];
        yield 'null' => [null, false];
    }

    #[DataProvider('explicitSearchRequestProvider')]
    public function testIsExplicitSearchRequest(?string $text, bool $expected): void
    {
        self::assertSame(
            $expected,
            WebSearchTopicPolicy::isExplicitSearchRequest($text),
            sprintf('text=%s', var_export($text, true)),
        );
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
