<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Single source of truth for "is this topic compatible with web search?".
 *
 * Pure asset/document generation topics (image / video / audio / office
 * documents) never benefit from internet context — the downstream handler
 * does not consume search results. Routing them through Brave Search
 * costs quota and adds latency for zero benefit, so they are excluded
 * from the search default regardless of any other signal (including an
 * explicit `tool_internet=true` opt-in: there is nothing useful to do
 * with the results).
 *
 * Used by `MessageProcessor` as the final web-search decision in both the
 * streaming and non-streaming pipelines.
 */
final class WebSearchTopicPolicy
{
    /**
     * Topics whose handler does not consume web context. Asset/document
     * generation only — chat, coding, summarisation and analysis can all
     * benefit from live context and are therefore NOT listed here.
     *
     * @var list<string>
     */
    public const NON_WEB_SEARCH_TOPICS = [
        'mediamaker',
        'officemaker',
        'text2pic',
        'text2vid',
        'text2sound',
        'text2doc',
    ];

    /**
     * Live-data / actuality signals. When a message contains any of these it
     * may genuinely need fresh information, so it is NEVER treated as a
     * trivial chat (the model's BWEBSEARCH vote is honoured). Kept lowercase;
     * matched as substrings against the lowercased raw message.
     *
     * @var list<string>
     */
    private const ACTUALITY_SIGNALS = [
        // English
        'today', 'now', 'latest', 'current', 'recent', 'news', 'price', 'weather',
        'score', 'stock', 'this week', 'this year', 'right now', 'up to date',
        // German
        'heute', 'jetzt', 'aktuell', 'aktuelle', 'neueste', 'neuste', 'wetter',
        'preis', 'kurs', 'nachrichten', 'gerade', 'derzeit', 'momentan',
        // Spanish
        'hoy', 'ahora', 'actual', 'últimas', 'ultimas', 'noticias', 'precio', 'tiempo',
        // French
        "aujourd'hui", 'maintenant', 'actuel', 'actuelle', 'dernières', 'dernieres',
        'nouvelles', 'prix', 'météo', 'meteo',
        // Italian
        'oggi', 'adesso', 'attuale', 'ultime', 'notizie', 'prezzo', 'meteo',
        // Turkish
        'bugün', 'bugun', 'şimdi', 'simdi', 'güncel', 'guncel', 'haber', 'fiyat',
        // Year anchors (post knowledge-cutoff content is usually time-sensitive)
        '2024', '2025', '2026',
    ];

    /**
     * Greeting / smalltalk / acknowledgement phrases. A message that is
     * essentially one of these carries no information need and must never
     * trigger a web search, regardless of the model's vote. Phrases are
     * matched word-bounded against a punctuation-stripped message, so short
     * anchors like "hi" or "ok" do not match inside longer words.
     *
     * @var list<string>
     */
    private const CONVERSATIONAL_PHRASES = [
        // German
        'hallo', 'hi', 'hey', 'moin', 'servus', 'na', 'tach',
        'guten morgen', 'guten tag', 'guten abend', 'gute nacht',
        'wie geht', 'wie gehts', 'wie geht es', 'wie geht es dir', 'wie geht es ihnen',
        'alles klar', 'alles gut', 'na wie gehts',
        'danke', 'vielen dank', 'dankeschön', 'danke schön', 'danke dir',
        'ok', 'okay', 'passt', 'super', 'cool', 'perfekt', 'top',
        'tschüss', 'tschuss', 'bis später', 'bis bald', 'ciao',
        // English
        'hello', 'yo', 'hiya', 'sup',
        'good morning', 'good afternoon', 'good evening', 'good night',
        'how are you', 'how are u', 'how is it going', 'hows it going',
        "how's it going", 'whats up', "what's up", 'how do you do',
        'thanks', 'thank you', 'thx', 'ty', 'thank u', 'many thanks',
        'nice', 'great', 'awesome', 'cheers', 'bye', 'goodbye', 'see you', 'see ya',
        // Spanish
        'hola', 'buenos días', 'buenos dias', 'buenas tardes', 'buenas noches',
        'cómo estás', 'como estas', 'qué tal', 'que tal', 'gracias', 'muchas gracias',
        'vale', 'adiós', 'adios', 'hasta luego',
        // French
        'bonjour', 'salut', 'bonsoir', 'coucou',
        'comment ça va', 'comment ca va', 'comment vas tu', 'ça va', 'ca va',
        'merci', 'merci beaucoup', "d'accord", 'au revoir', 'à bientôt', 'a bientot',
        // Italian
        'buongiorno', 'buonasera', 'buonanotte', 'come stai', 'come va',
        'grazie', 'grazie mille', 'va bene', 'arrivederci', 'a presto',
        // Turkish
        'merhaba', 'selam', 'günaydın', 'gunaydin', 'nasılsın', 'nasilsin', 'naber',
        'teşekkür', 'tesekkur', 'teşekkürler', 'sağol', 'sagol', 'tamam',
        'görüşürüz', 'gorusuruz', 'iyi geceler',
    ];

    /**
     * Upper bound (in words) for the "ultra-short noise" trivial check.
     * Deliberately conservative (2 instead of the plan's example of 3) so
     * genuine short queries like "wann kommt gta" are not silently
     * suppressed; greetings of any length are still caught by the phrase list.
     */
    private const TRIVIAL_MAX_WORDS = 2;

    /**
     * True if the topic is a pure asset/document generation topic and
     * web search should be suppressed regardless of the prompt's
     * `tool_internet` flag.
     */
    public static function isNonWebSearchTopic(?string $topic): bool
    {
        return null !== $topic && '' !== $topic && in_array($topic, self::NON_WEB_SEARCH_TOPICS, true);
    }

    /**
     * Deterministic negative filter: true when the message is an obvious
     * greeting / smalltalk / acknowledgement (or ultra-short question-less
     * noise) that carries no information need.
     *
     * Used to veto the model's BWEBSEARCH vote so trivial chats such as
     * "Hey, wie gehts?" never trigger a web search even when an over-eager
     * sorting model votes for one. Errs on the side of NOT suppressing: any
     * actuality signal (today / latest / price / weather / a year, …) makes a
     * message non-trivial, and an explicit user opt-in bypasses this gate
     * entirely (see {@see shouldSearch()}).
     */
    public static function isTrivialConversational(?string $text): bool
    {
        if (null === $text) {
            return false;
        }

        $trimmed = trim($text);
        if ('' === $trimmed) {
            return false;
        }

        $lowerRaw = mb_strtolower($trimmed);

        // A live-data signal means the message may genuinely need fresh
        // information — never treat it as trivial.
        foreach (self::ACTUALITY_SIGNALS as $signal) {
            if (str_contains($lowerRaw, $signal)) {
                return false;
            }
        }

        // Collapse every run of non-letters to a single space so the
        // space-delimited phrase anchors below match regardless of
        // punctuation ("wie gehts?" → " wie gehts ").
        $normalized = preg_replace('/[^\p{L}]+/u', ' ', $lowerRaw) ?? '';
        $normalized = trim($normalized);
        $padded = ' '.$normalized.' ';

        foreach (self::CONVERSATIONAL_PHRASES as $phrase) {
            if (str_contains($padded, ' '.$phrase.' ')) {
                return true;
            }
        }

        // Ultra-short, question-less one-liners ("lol", "ok danke") carry no
        // information need. A trailing question mark means the user is asking
        // something, so those are left to the model vote.
        if ('' === $normalized) {
            return false;
        }
        $words = preg_split('/\s+/', $normalized) ?: [];
        $wordCount = count($words);

        return $wordCount <= self::TRIVIAL_MAX_WORDS && !str_contains($trimmed, '?');
    }

    /**
     * Decide whether to run a web search, trusting the model's judgment but
     * vetoing it for obviously trivial chats.
     *
     * Decision rule (in order of precedence):
     *   1. Prompt has explicit `tool_internet=false` → false
     *      (a deliberate prompt-level opt-out is a HARD disable: it beats the
     *      per-message user request, because the prompt author decided this
     *      task must never consume web context, e.g. a translation prompt)
     *   2. User explicitly requested search for THIS message (chat toggle /
     *      `/search` command)                        → true
     *      (an explicit per-message opt-in beats the topic gate and the
     *      triviality veto, like `tool_internet=true`)
     *   3. Prompt has explicit `tool_internet=true`  → true
     *      (explicit opt-in beats the NON_WEB_SEARCH exclusion: power users
     *      can wire search into a custom media-generation prompt that
     *      consumes web context in its system message, e.g. "image of
     *      today's headlines")
     *   4. Topic is a NON_WEB_SEARCH topic           → false
     *      (the stock handler does not consume web context)
     *   5. Otherwise (`tool_internet` is `null`)     → trust the classifier's
     *      `BWEBSEARCH` vote, UNLESS the message is an obvious greeting /
     *      smalltalk (see {@see isTrivialConversational()}). The veto stops an
     *      over-eager sorting model from searching on every "Hey, wie gehts?".
     *      No vote (e.g. the fast-path heuristic, which never calls the model)
     *      means no search, so trivial chats stay fast.
     *
     * Pass `$userRequestedSearch` as the resolved per-message flag (frontend
     * web-search toggle / `/search`). Pass `$promptToolInternet` as the raw
     * value from `$promptMetadata['tool_internet'] ?? null` — the function
     * distinguishes the three states (true / false / null) intentionally.
     * Pass `$classifierVote` as the classifier's `web_search` hint
     * (`$classification['web_search'] ?? null`) and `$messageText` as the raw
     * user message so the triviality veto can run.
     */
    public static function shouldSearch(
        ?string $topic,
        bool $userRequestedSearch = false,
        ?bool $promptToolInternet = null,
        ?bool $classifierVote = null,
        ?string $messageText = null,
    ): bool {
        // Rule 1: explicit prompt opt-out is a hard disable (beats everything).
        if (false === $promptToolInternet) {
            return false;
        }

        // Rule 2: explicit per-message user request forces a search.
        if ($userRequestedSearch) {
            return true;
        }

        // Rule 3: explicit prompt opt-in forces a search.
        if (true === $promptToolInternet) {
            return true;
        }

        // Rule 4: media-generation topics with no explicit opt-in stay off.
        if (self::isNonWebSearchTopic($topic)) {
            return false;
        }

        // Rule 5: trust the model's BWEBSEARCH vote, but veto trivial chats.
        if (true !== $classifierVote) {
            return false;
        }

        return !self::isTrivialConversational($messageText);
    }
}
