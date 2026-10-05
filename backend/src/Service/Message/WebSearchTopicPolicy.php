<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Single source of truth for "does this message need a web search?".
 *
 * A web search runs only when the user explicitly asks for one for THIS
 * message (chat toggle, `/search`, or a plain-language request such as
 * "search the web for …"), or when the sorter judged that a correct answer
 * needs fresh, changing information (weather, prices, news, …). A prompt or
 * assistant setting can only ALLOW search (the classifier decides) or switch
 * it off — it never forces a search on every message.
 *
 * Pure asset/document generation topics (image / video / audio / office
 * documents) never benefit from internet context — the downstream handler
 * does not consume search results, so a vote-triggered search is skipped.
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
     * matched as whole words against the normalized message, so "now" does
     * not fire inside "know" and "actual" not inside "actually".
     *
     * @var list<string>
     */
    private const ACTUALITY_SIGNALS = [
        // English
        'today', 'tonight', 'tomorrow', 'yesterday', 'now', 'latest', 'current', 'currently',
        'recent', 'recently', 'news', 'price', 'prices', 'weather', 'forecast', 'score',
        'scores', 'stock', 'stocks', 'exchange rate', 'this week', 'this year', 'right now',
        'up to date', 'dollar', 'euro', 'bitcoin',
        // German
        'heute', 'gestern', 'jetzt', 'aktuell', 'aktuelle', 'aktuellen', 'aktueller',
        'aktuelles', 'neueste', 'neuesten', 'neuste', 'wetter', 'preis', 'preise', 'kurs',
        'kurse', 'wechselkurs', 'aktie', 'aktien', 'börse', 'nachrichten', 'gerade', 'derzeit',
        'momentan',
        // Spanish
        'hoy', 'mañana', 'ayer', 'ahora', 'actual', 'actualmente', 'últimas', 'ultimas',
        'noticias', 'precio', 'precios', 'tiempo', 'clima', 'bolsa',
        // French
        "aujourd'hui", 'demain', 'maintenant', 'actuel', 'actuelle', 'actuellement',
        'dernières', 'dernieres', 'nouvelles', 'prix', 'météo', 'meteo', 'bourse', 'cours',
        // Italian
        'oggi', 'domani', 'ieri', 'adesso', 'attuale', 'ultime', 'notizie', 'prezzo', 'borsa',
        // Turkish
        'bugün', 'bugun', 'yarın', 'yarin', 'dün', 'dun', 'şimdi', 'simdi', 'güncel', 'guncel',
        'haber', 'haberler', 'fiyat', 'fiyatı', 'hava durumu', 'kur', 'dolar',
    ];

    /**
     * Plain-language requests to search the web for THIS message ("search
     * the web for …", "such im Internet nach …", "google mal …"). They count
     * as an explicit per-message request, exactly like the chat toggle or
     * `/search`. Matched against the lowercased raw message.
     *
     * Every pattern is anchored to the start of a sentence or clause plus an
     * optional politeness lead-in ("please", "can you", "kannst du mal"), so
     * only a request matches — talking ABOUT searching ("why do people search
     * the web for medical advice?", "how does Google search work?") does not.
     *
     * @var list<string>
     */
    private const EXPLICIT_SEARCH_PATTERNS = [
        // English
        '/'.self::REQUEST_START_EN.'(?:search|browse|check|look)\s+(?:on\s+)?(?:the\s+)?(?:web|internet|net|online)\b/u',
        '/'.self::REQUEST_START_EN.'look\s+(?:it|this|that|them)\s+up\s+online\b/u',
        '/'.self::REQUEST_START_EN.'(?:do|run)\s+an?\s+(?:web|internet|online)\s+search\b/u',
        '/'.self::REQUEST_START_EN.'google\s+(?:it|this|that|for)\b/u',
        '/'.self::REQUEST_START_EN.'(?:find|search)\b[^.?!]*\b(?:on|from)\s+the\s+(?:web|internet)\b/u',
        // German
        '/'.self::REQUEST_START_DE.'(?:such|suche|durchsuche|recherchier|recherchiere|schau|schaue|guck|gucke)\b[^.?!]*(?:\b(?:im|ins|das|dem)\s+(?:internet|netz|web)\b|\bonline\b)/u',
        '/'.self::REQUEST_START_DE.'(?:kannst|könntest|koenntest|würdest|wuerdest)\s+du\b[^.?!]*(?:\b(?:im|ins)\s+(?:internet|netz|web)\b|\bonline\b)[^.?!]*\b(?:suchen|nachsuchen|nachschauen|nachsehen|nachgucken|recherchieren|schauen|gucken)\b/u',
        '/'.self::REQUEST_START_DE.'(?:kannst|könntest|koenntest|würdest|wuerdest)\s+du\b[^.?!]*\bgoogeln\b/u',
        '/'.self::REQUEST_START_DE.'(?:mach|mache|starte|führ|führe|fuehr|fuehre)\s+(?:mal\s+)?(?:eine\s+)?(?:websuche|internetsuche|online-suche|onlinesuche)\b/u',
        '/'.self::REQUEST_START_DE.'(?:websuche|internetsuche)\s*:/u',
        '/'.self::REQUEST_START_DE.'(?:google|googel)\s+(?:mal|bitte|das|nach)\b/u',
        // Spanish
        '/'.self::REQUEST_START_ES.'(?:busca|buscar|búscalo|buscalo|investiga|investigar)\b[^.?!]*\b(?:en|por)\s+(?:internet|la\s+web|google|línea|linea)\b/u',
        // French
        '/'.self::REQUEST_START_FR.'(?:cherche|chercher|recherche|rechercher)\b[^.?!]*\b(?:sur\s+(?:internet|le\s+web|google)|en\s+ligne)\b/u',
        // Italian
        '/'.self::REQUEST_START_IT.'(?:cerca|cercare)\b[^.?!]*\b(?:su|in)\s+(?:internet|web|google)\b/u',
        // Turkish (the verb comes last: "internette ara", "internetten bakar mısın")
        '/\b(?:internette|internetten|webde|google\'da|googleda)\s+(?:ara|araştır|arastir|bak)(?:\s*$|\s*[:.!?,]|\s+(?:mısın|misin|bakar|lütfen|lutfen)\b)/u',
    ];

    private const CLAUSE_START = '(?:^|[.!?;:,]\s*)[¿¡]?\s*';

    private const REQUEST_START_EN = self::CLAUSE_START.'(?:(?:please|pls|just|hey|ok|okay|now|then)\s+)*(?:(?:can|could|would|will)\s+you\s+(?:please\s+|just\s+)*)?';

    private const REQUEST_START_DE = self::CLAUSE_START.'(?:(?:bitte|hey|ok|okay|jetzt|dann)\s+)*';

    private const REQUEST_START_ES = self::CLAUSE_START.'(?:(?:por\s+favor|oye|vale)\s*,?\s*)*(?:(?:puedes|podrías|podrias)\s+)?';

    private const REQUEST_START_FR = self::CLAUSE_START.'(?:(?:s\'il\s+te\s+plaît|s\'il\s+te\s+plait|stp)\s*,?\s*)*(?:(?:peux|pourrais)[-\s]tu\s+)?';

    private const REQUEST_START_IT = self::CLAUSE_START.'(?:(?:per\s+favore)\s*,?\s*)*(?:puoi\s+)?';

    /**
     * Matches an explicit four-digit 20xx year token (e.g. "2026", "olympics
     * 2031"). Treated as an actuality signal so the triviality veto stays
     * future-proof instead of relying on a hardcoded year list.
     */
    private const YEAR_SIGNAL_PATTERN = '/\b20\d{2}\b/';

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
     * Upper bound (in words) for the "ultra-short noise" trivial check and
     * for what may remain of a message once its greeting / smalltalk phrases
     * are removed. Deliberately conservative so genuine short queries like
     * "wann kommt gta" — or a greeting followed by a real question ("Hi, wie
     * steht der Dollar zum Euro?") — are not silently suppressed.
     */
    private const TRIVIAL_MAX_WORDS = 2;

    /**
     * Deictic / referential anchors: pronouns, demonstratives, attachment
     * nouns, and perception verbs across the supported UI languages. When a
     * message that CARRIES an attachment contains one of these, the user is
     * talking about that file ("what is that?", "was ist das?", "how much
     * does this cost?", "is this contract still valid?") — the referent lives
     * in the file, not in the text. The web-search query must then be built
     * WITH the file's content (extracted text, transcript, or a vision
     * identification — see AttachmentSearchContextResolver), never from the
     * literal question words alone.
     *
     * Bare articles that double as demonstratives (German "das", Spanish
     * "esta") are kept: with a file attached in the SAME message they are
     * usually the referent. Bare "it" / German "es" / Spanish "es" ("is")
     * are NOT listed — they collide with dummy pronouns and the copula
     * ("is it raining in Berlin today?", "¿cuál es el precio?") and would
     * pull file content into a self-contained search. Pointing at a file
     * with those words still matches via a phrase below ("what is it",
     * "was ist es") or via this/that/das/esto. Turkish bare "o" is
     * excluded for the same reason (collides with Spanish "o" / "or").
     *
     * Matched word-bounded against the punctuation-normalized message.
     *
     * @var list<string>
     */
    private const ATTACHMENT_REFERENCE_ANCHORS = [
        // English pronouns/demonstratives (no bare "it" — see above)
        'this', 'that', 'these', 'those',
        'what is it',
        // German (no bare "es" — it is both "it" and the Spanish copula)
        'das', 'dies', 'diese', 'dieser', 'dieses', 'hier', 'darauf',
        'was ist es',
        // Spanish
        'esto', 'eso', 'esta', 'este', 'esa', 'ese', 'aquí', 'aqui',
        // French
        'ceci', 'cela', 'ça', 'ca', 'ici',
        // Italian
        'questo', 'questa', 'quello', 'quella', 'qui',
        // Turkish
        'bu', 'şu', 'su', 'bunu', 'şunu', 'bunun', 'burada',
        // Image nouns (all languages) — "what's in the picture?"
        'image', 'picture', 'photo', 'pic', 'screenshot', 'scan',
        'bild', 'foto', 'aufnahme', 'imagen', 'imagem', 'immagine',
        'resim', 'resmin', 'fotoğraf', 'fotograf', 'görsel', 'gorsel',
        // Document nouns — "is the contract still valid?"
        'file', 'document', 'doc', 'pdf', 'page', 'contract', 'invoice',
        'report', 'article', 'attachment',
        'datei', 'dokument', 'seite', 'vertrag', 'rechnung', 'bericht',
        'anhang', 'unterlagen',
        'archivo', 'documento', 'contrato', 'factura', 'informe',
        'fichier', 'contrat', 'facture', 'rapport',
        'fattura', 'contratto', 'documento',
        'dosya', 'belge', 'fatura', 'sözleşme', 'sozlesme', 'rapor',
        // Audio/video nouns — "who is speaking in the recording?"
        'audio', 'recording', 'video', 'clip', 'song', 'track', 'podcast',
        'transcript', 'voicemail',
        'aufzeichnung', 'lied', 'transkript', 'sprachnachricht',
        'grabación', 'grabacion', 'canción', 'cancion',
        'enregistrement', 'chanson', 'registrazione', 'canzone',
        'kayıt', 'kayit', 'şarkı', 'sarki', 'ses',
        // Perception verbs — "what do you see?" / "what do you hear?" next
        // to an attachment asks about the attachment even without a pronoun.
        'see', 'hear', 'shown', 'siehst', 'sehen', 'erkennst', 'hörst',
        'hoerst', 'ves', 'oyes', 'vois', 'entends', 'vedi', 'senti',
        'görüyorsun', 'goruyorsun', 'duyuyorsun',
    ];

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
     * message non-trivial, and so does a greeting followed by a real question
     * ("Hi, wie steht der Dollar zum Euro?"). An explicit per-message request
     * bypasses this gate entirely (see {@see shouldSearch()}).
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

        // Any explicit 20xx year is a (future-proof) actuality signal.
        if (1 === preg_match(self::YEAR_SIGNAL_PATTERN, $lowerRaw)) {
            return false;
        }

        $padded = ' '.self::normalizeWords($lowerRaw).' ';
        if ('' === trim($padded)) {
            return false;
        }

        // A live-data signal means the message may genuinely need fresh
        // information — never treat it as trivial.
        foreach (self::ACTUALITY_SIGNALS as $signal) {
            if (str_contains($padded, ' '.self::normalizeWords($signal).' ')) {
                return false;
            }
        }

        // Strip every greeting / smalltalk phrase (longest first, so "wie
        // geht es dir" goes before "wie geht"). What remains is the actual
        // content of the message.
        $remaining = $padded;
        $matchedPhrase = false;
        foreach (self::conversationalPhrasesLongestFirst() as $phrase) {
            $needle = ' '.$phrase.' ';
            while (str_contains($remaining, $needle)) {
                $remaining = str_replace($needle, ' ', $remaining);
                $matchedPhrase = true;
            }
        }

        $remainingWords = self::wordCount($remaining);
        if ($matchedPhrase) {
            // A fully consumed greeting is small talk. A short remainder is
            // too — unless it is phrased as a question ("Hi, Öffnungszeiten
            // Städel?"), which the model vote must still see.
            return 0 === $remainingWords
                || ($remainingWords <= self::TRIVIAL_MAX_WORDS && !self::asksAQuestion($trimmed));
        }

        // Ultra-short, question-less one-liners ("lol") carry no information
        // need. A question mark means the user is asking something, so those
        // are left to the model vote.
        return $remainingWords <= self::TRIVIAL_MAX_WORDS && !self::asksAQuestion($trimmed);
    }

    private static function asksAQuestion(string $text): bool
    {
        return str_contains($text, '?') || str_contains($text, '¿');
    }

    /**
     * True when the message itself asks to search the web ("search the web
     * for …", "such im Internet nach …", "google mal …", "busca en internet
     * …"). Treated like the chat toggle / `/search`: an explicit request for
     * THIS message.
     */
    public static function isExplicitSearchRequest(?string $text): bool
    {
        if (null === $text || '' === trim($text)) {
            return false;
        }

        $lower = mb_strtolower($text);
        foreach (self::EXPLICIT_SEARCH_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $lower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collapse every run of non-letters to a single space so space-delimited
     * phrase anchors match regardless of punctuation ("wie gehts?" → "wie gehts").
     */
    private static function normalizeWords(string $lower): string
    {
        return trim(preg_replace('/[^\p{L}]+/u', ' ', $lower) ?? '');
    }

    private static function wordCount(string $text): int
    {
        $trimmed = trim($text);

        return '' === $trimmed ? 0 : count(preg_split('/\s+/', $trimmed) ?: []);
    }

    /**
     * @return list<string>
     */
    private static function conversationalPhrasesLongestFirst(): array
    {
        $phrases = array_map(self::normalizeWords(...), self::CONVERSATIONAL_PHRASES);
        usort($phrases, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $phrases;
    }

    /**
     * True when the message text refers to something the user attached
     * (image, document, audio, video — uploaded or selected from the library)
     * rather than to a self-contained, searchable subject.
     *
     * "what is that?" with an attached photo, "is this still valid?" with a
     * contract PDF: the referent lives in the FILE. A search query built from
     * the text alone ("what is that") is meaningless, so when this predicate
     * matches, MessageProcessor resolves the attachment's content first
     * (AttachmentSearchContextResolver) and the query generator builds the
     * search phrase from it. Blank text next to an attachment is the same
     * situation — the attachment IS the message.
     *
     * Only meaningful for messages that actually carry an attachment; callers
     * must gate on that.
     */
    public static function refersToAttachment(?string $text): bool
    {
        if (null === $text || '' === trim($text)) {
            // Attachment with no text: the attachment IS the message.
            return true;
        }

        // Same normalization as the triviality check: collapse every run of
        // non-letters to a single space so punctuation-hugging tokens match
        // ("what is that?" → " what is that ").
        $normalized = preg_replace('/[^\p{L}]+/u', ' ', mb_strtolower($text)) ?? '';
        $padded = ' '.trim($normalized).' ';

        foreach (self::ATTACHMENT_REFERENCE_ANCHORS as $anchor) {
            if (str_contains($padded, ' '.$anchor.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether to run a web search: only on an explicit per-message
     * request, or when the classifier judged that fresh information is
     * needed — vetoed for obviously trivial chats.
     *
     * Decision rule (in order of precedence):
     *   1. Prompt/assistant has `tool_internet=false` → false
     *      (a deliberate opt-out is a HARD disable: it beats the per-message
     *      user request, because the prompt author decided this task must
     *      never consume web context, e.g. a translation prompt)
     *   2. User explicitly requested search for THIS message (chat toggle,
     *      `/search` command, or a plain-language request in the text — see
     *      {@see isExplicitSearchRequest()})          → true
     *      (beats the topic gate and the triviality veto)
     *   3. Topic is a NON_WEB_SEARCH topic           → false
     *      (the stock handler does not consume web context)
     *   4. Otherwise → trust the classifier's `BWEBSEARCH` vote, UNLESS the
     *      message is an obvious greeting / smalltalk (see
     *      {@see isTrivialConversational()}). No vote (e.g. a sorter-skipping
     *      routing layer) means no search.
     *
     * `tool_internet=true` is NOT a force: like the "web search" switch in
     * ChatGPT, Claude or Gemini it only allows the search, and the classifier
     * decides per message whether the answer needs fresh information. Older
     * installs stored `true` from a pre-selected checkbox, which turned every
     * "Hi" into a search.
     *
     * Attachment-referring questions ("what is that?" + photo) are NOT vetoed
     * here: when the search runs, MessageProcessor resolves the attachment's
     * content ({@see refersToAttachment()}, AttachmentSearchContextResolver)
     * and the query is built from what the file actually shows/says. Only
     * when that resolution comes back empty does MessageProcessor drop a
     * purely vote-triggered search (a text-only query would be garbage).
     *
     * Pass `$userRequestedSearch` as the resolved per-message flag (frontend
     * web-search toggle / `/search`); a request written in `$messageText` is
     * detected here. Pass `$promptToolInternet` as the raw value from
     * `$promptMetadata['tool_internet'] ?? null` — only `false` changes the
     * outcome. Pass `$classifierVote` as the classifier's `web_search` hint
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
        if ($userRequestedSearch || self::isExplicitSearchRequest($messageText)) {
            return true;
        }

        // Rule 3: media-generation topics never consume web context.
        if (self::isNonWebSearchTopic($topic)) {
            return false;
        }

        // Rule 4: trust the model's BWEBSEARCH vote, but veto trivial chats.
        if (true !== $classifierVote) {
            return false;
        }

        return !self::isTrivialConversational($messageText);
    }
}
