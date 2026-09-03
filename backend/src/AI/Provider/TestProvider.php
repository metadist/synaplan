<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\FileAnalysisProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\ToolCallingChatProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\AI\Tool\CatalogToolUse;

class TestProvider implements ChatProviderInterface, ToolCallingChatProviderInterface, EmbeddingProviderInterface, VisionProviderInterface, ImageGenerationProviderInterface, SpeechToTextProviderInterface, TextToSpeechProviderInterface, FileAnalysisProviderInterface
{
    private const FAKE_TOKENS_PER_EMBED = 8;

    public function __construct(
        private readonly string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function getName(): string
    {
        return 'test';
    }

    public function getDisplayName(): string
    {
        return 'Test Provider';
    }

    public function getDescription(): string
    {
        return 'Mock provider for testing and development';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'vision', 'image_generation', 'speech_to_text', 'text_to_speech', 'file_analysis'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => 'test-model',
            'embedding' => 'test-embedding',
        ];
    }

    public function getStatus(): array
    {
        return [
            'healthy' => true,
            'latency_ms' => 10,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getRequiredEnvVars(): array
    {
        return []; // Test provider requires no configuration
    }

    public function supportsToolCalling(string $model): bool
    {
        return CatalogToolUse::supports($this->getName(), $model);
    }

    public function chat(array $messages, array $options = []): array
    {
        $toolResponse = $this->maybeToolResponse($messages, $options);
        if (null !== $toolResponse) {
            return $toolResponse;
        }

        $content = $this->generateContent($messages, $options);
        $tokenEstimate = (int) ceil(strlen($content) / 4);

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => $tokenEstimate,
                'total_tokens' => 10 + $tokenEstimate,
                'cached_tokens' => 0,
                'cache_creation_tokens' => 0,
            ],
        ];
    }

    /**
     * Deterministic tool-calling surface for gateway / loop tests.
     *
     * TOOLTEST:<name>:<json> on the last user message (when `tools` are
     * present) returns a matching tool_call. A trailing `role: tool` turn
     * answers "Tool result received: …" so T3/T4 can drive a two-round
     * exchange without a live upstream.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>|null
     */
    private function maybeToolResponse(array $messages, array $options): ?array
    {
        $last = [] !== $messages ? $messages[array_key_last($messages)] : null;
        if (!is_array($last)) {
            return null;
        }

        if ('tool' === ($last['role'] ?? '')) {
            $received = is_string($last['content'] ?? null) ? $last['content'] : '';

            return $this->wrapChatText('Tool result received: '.$received);
        }

        if (!isset($options['tools']) || !is_array($options['tools']) || [] === $options['tools']) {
            return null;
        }

        $lastUser = null;
        for ($i = count($messages) - 1; $i >= 0; --$i) {
            if ('user' === ($messages[$i]['role'] ?? '')) {
                $lastUser = $messages[$i];
                break;
            }
        }
        if (!is_array($lastUser)) {
            return null;
        }

        [$userContent] = $this->flattenContent($lastUser['content'] ?? '');
        $trimmed = ltrim($userContent);
        if (1 !== preg_match('/^TOOLTEST:([^:]+):(.*)$/s', $trimmed, $match)) {
            return null;
        }

        $name = $match[1];
        $arguments = '' !== $match[2] ? $match[2] : '{}';
        if (null === json_decode($arguments)) {
            $arguments = '{}';
        }

        return [
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_test_1',
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => $arguments,
                ],
            ]],
            'finish_reason' => 'tool_calls',
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 8,
                'total_tokens' => 18,
                'cached_tokens' => 0,
                'cache_creation_tokens' => 0,
            ],
        ];
    }

    /**
     * @return array{content: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}}
     */
    private function wrapChatText(string $content): array
    {
        $tokenEstimate = (int) ceil(strlen($content) / 4);

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => $tokenEstimate,
                'total_tokens' => 10 + $tokenEstimate,
                'cached_tokens' => 0,
                'cache_creation_tokens' => 0,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     */
    private function generateContent(array $messages, array $options = []): string
    {
        $lastMessage = end($messages);
        [$userContent, $imageCount] = $this->flattenContent($lastMessage['content'] ?? 'hello');
        $userMessage = strtolower($userContent);

        $systemContent = 'system' === $messages[0]['role'] ? ($messages[0]['content'] ?? '') : '';

        // Schema-aware structured-output path: every real call-site that
        // expects JSON back (MessageSorter, TaskPlanner, MemoryExtractionService,
        // …) now unconditionally attaches a StructuredOutputSchema, the same
        // way a real provider would receive one. Marker detection below still
        // decides WHICH mock generator runs — this only makes the generator
        // itself schema-conformant (real booleans, schema-derived enums) and
        // self-validating instead of a schema-oblivious guess.
        $schema = $options['structured_output'] ?? null;
        $schema = $schema instanceof StructuredOutputSchema ? $schema : null;

        // Sort/classification prompt (tools:sort): return realistic JSON
        if (str_contains($systemContent, 'BTOPIC') && str_contains($systemContent, 'BWEBSEARCH')) {
            return $this->mockSortClassification($userContent, $systemContent, $schema);
        }

        // Multi-task planner prompt (tools:plan): return a schema-valid task plan.
        // Deterministic so E2E can exercise the multi-node DAG + task cards.
        if (str_contains($systemContent, 'Multi-Task Planner')) {
            return $this->mockTaskPlan($userContent, $schema);
        }

        // Memory extraction (tools:memory_extraction): the user prompt built by
        // MemoryExtractionService carries these two stable markers.
        if (str_contains($userContent, 'Current Message (from the user):')
            && str_contains($userContent, '"action": "create"')) {
            return $this->mockMemoryExtraction($userContent, $schema);
        }

        // Search-query-style request (e.g. SearchQueryGenerator with tools:search prompt): return cleaned query like fallbackExtraction
        if (str_contains($systemContent, 'search') && str_contains($systemContent, 'query')) {
            return $this->mockSearchQueryExtraction($userContent);
        }

        // Vision request: the message carries inline image parts. Answer with
        // a deterministic description BEFORE the image-GENERATION keyword
        // branch below — prompts like "Describe what you see in this image"
        // contain "image" and would otherwise trigger the picsum placeholder.
        if ($imageCount > 0) {
            return sprintf(
                'Test image analysis: I can see %d attached image%s. This is a deterministic mock description from the test provider.',
                $imageCount,
                1 === $imageCount ? '' : 's'
            );
        }

        // Image generation keywords
        if (preg_match('/(bild|image|picture|foto|photo|draw|zeichne|erstelle.*bild)/i', $userMessage)) {
            return "Here's a **sample image** — in demo mode a placeholder stands in for real AI image generation.\n\n[IMAGE:https://picsum.photos/800/600]\n\n".$this->demoSetupFooter();
        }

        // Video generation keywords
        if (preg_match('/(video|film|movie|clip|animation)/i', $userMessage)) {
            return "Here's a **sample video** — in demo mode a placeholder stands in for real AI video generation.\n\n[VIDEO:https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4]\n\n".$this->demoSetupFooter();
        }

        // Different responses based on content
        $responses = [
            'hello' => "Hello! **You're in demo mode** — no AI provider is connected yet, so replies are canned. You can still try the interface: ask for an image or a video to see how answers look.\n\n".$this->demoSetupFooter(),
            'how are you' => "All systems are running fine — but **you're in demo mode**, so this reply is canned, not real AI.\n\n".$this->demoSetupFooter(),
            'what can you do' => "Once an AI provider is connected, Synaplan answers questions, searches your documents, generates images, audio and video, and works via chat widgets, WhatsApp and email.\n\n**Right now you're in demo mode** — replies are canned until a provider is connected.\n\n".$this->demoSetupFooter(),
            // Support for smoke test prompts
            'smoke test' => 'success',
            'answer with "success"' => 'success',
        ];

        // Check for specific keywords
        foreach ($responses as $keyword => $response) {
            if (str_contains($userMessage, $keyword)) {
                return $response;
            }
        }

        // Default response with context.
        //
        // This text is the FIRST thing a fresh install answers when no real AI
        // provider key is configured yet (TestProvider is the dev fallback in
        // ModelConfigService::getDefaultModel). It must onboard the user with
        // an ACTION, not instructions: the [[SETUP_CTA]] marker below is
        // rendered as a button that signs the guest in as the seeded admin
        // and opens /admin/setup.
        $contextInfo = count($messages) > 1 ? ' (message #'.count($messages).' in this conversation)' : '';

        return "**You're in demo mode** — no AI provider is connected yet, so this is a canned reply to your message '{$userMessage}'{$contextInfo}.\n\n"
            .$this->demoSetupFooter();
    }

    /**
     * Flatten a chat message's content to plain text.
     *
     * Once a vision-capable model is selected, ChatHandler sends the same
     * multimodal shape real providers receive: an array of parts
     * (['type' => 'text', ...] / ['type' => 'image_url', ...]). The mock must
     * accept that shape too — assuming a plain string crashed with a
     * TypeError (strtolower on an array) that surfaced as an HTTP 500 on the
     * WhatsApp image webhook.
     *
     * @return array{0: string, 1: int} [flattened text, number of image parts]
     */
    private function flattenContent(mixed $content): array
    {
        if (is_string($content)) {
            return [$content, 0];
        }

        if (!is_array($content)) {
            return ['', 0];
        }

        $textParts = [];
        $imageCount = 0;
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            if ('text' === ($part['type'] ?? null) && is_string($part['text'] ?? null)) {
                $textParts[] = $part['text'];
            } elseif ('image_url' === ($part['type'] ?? null)) {
                ++$imageCount;
            }
        }

        return [implode("\n", $textParts), $imageCount];
    }

    /**
     * Shared call-to-action for every user-facing demo reply.
     *
     * `[[SETUP_CTA]]` is a marker, not a markdown link: MessageText.vue
     * turns it into a button that signs the guest in as the seeded admin
     * and opens /admin/setup. Markdown links must not appear here — the
     * chat renderer turns `[label](url)` into broken chips, and a raw
     * /admin/setup href is useless to a guest (the typical first-run user).
     */
    private function demoSetupFooter(): string
    {
        return "[[SETUP_CTA]]\n\n"
            .'A free Groq key or a local Ollama model — no key needed.';
    }

    /**
     * Mock sort/classification: parse the user message JSON and return a
     * realistic classification response that MessageSorter::parseResponse()
     * can decode. Mirrors what a real LLM would return for the tools:sort
     * prompt: the same JSON object with BTOPIC, BLANG, BWEBSEARCH (and
     * optionally BMEDIA, BDURATION, BRESOLUTION, BINPUTMODE) updated.
     *
     * Schema-aware: BWEBSEARCH/BMULTI are real JSON booleans — matching
     * {@see \App\AI\StructuredOutput\Schema\SortClassificationSchema}'s
     * `type: boolean` — instead of the `0`/`1` a hand-rolled stub would
     * reach for. When a schema is supplied its BTOPIC enum (the caller's
     * live topic list) takes priority over parsing the system prompt's
     * quoted strings, and the chosen topic is validated against it before
     * returning — a locally-invented topic would never survive strict
     * decoding on a real provider either.
     */
    private function mockSortClassification(string $userContent, string $systemContent, ?StructuredOutputSchema $schema): string
    {
        $data = json_decode($userContent, true);
        if (!is_array($data)) {
            $fallback = ['BTOPIC' => 'general', 'BLANG' => 'en', 'BWEBSEARCH' => false];
            if (null !== $schema) {
                $this->assertMatchesSchema($fallback, $schema);
            }

            return json_encode($fallback, JSON_THROW_ON_ERROR);
        }

        $text = strtolower($data['BTEXT'] ?? '');
        $fileText = strtolower($data['BFILETEXT'] ?? '');

        // Keep the inbound BLANG (UI locale / previous detection) when the
        // heuristic cannot confidently detect a language from the text.
        $data['BLANG'] = $this->detectLanguage($text ?: $fileText, is_string($data['BLANG'] ?? null) ? $data['BLANG'] : 'en');
        $data['BWEBSEARCH'] = $this->needsWebSearch($text);
        // Always set BMULTI explicitly. The inbound JSON omits it (so a real
        // model that echoes without deciding leaves multi_step = null and the
        // planner still runs). The test stub must vote, from the same
        // predicates mockTaskPlan() routes on, so the two cannot disagree.
        $data['BMULTI'] = $this->needsMultiStepPlan($text ?: $fileText);

        $topicEnum = $this->schemaTopicEnum($schema);
        $availableTopics = $topicEnum ?? $this->extractAvailableTopics($systemContent);
        $classification = $this->classifyTopic($text ?: $fileText, $data, $availableTopics);

        $data['BTOPIC'] = $classification['topic'];

        // A real schema-constrained provider cannot emit a topic outside the
        // enum; a mock that did would hide a bug in classifyTopic() instead
        // of surfacing it, so fall back to `general` exactly like
        // MessageSorter::validateTopic() does server-side.
        if (null !== $topicEnum && !in_array($data['BTOPIC'], $topicEnum, true)) {
            $data['BTOPIC'] = 'general';
        }

        // With a schema attached, BMEDIA/BDURATION/BRESOLUTION/BINPUTMODE are
        // modelled as nullable-but-required (strict mode forbids omittable
        // keys — see SortClassificationSchema's docblock): a non-media
        // message must still carry them, explicitly null, not omit them.
        if (null !== $schema) {
            $data['BMEDIA'] = $classification['media_type'] ?? null;
            $data['BDURATION'] = $classification['duration'] ?? null;
            $data['BRESOLUTION'] = $classification['resolution'] ?? null;
            $data['BINPUTMODE'] = $classification['input_mode'] ?? null;
        } else {
            if (isset($classification['media_type'])) {
                $data['BMEDIA'] = $classification['media_type'];
            }
            if (isset($classification['duration'])) {
                $data['BDURATION'] = $classification['duration'];
            }
            if (isset($classification['resolution'])) {
                $data['BRESOLUTION'] = $classification['resolution'];
            }
            if (isset($classification['input_mode'])) {
                $data['BINPUTMODE'] = $classification['input_mode'];
            }
        }

        if (null !== $schema) {
            $this->assertMatchesSchema($data, $schema);
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>|null the schema's BTOPIC enum, or null when no
     *                           schema was supplied or it left BTOPIC unconstrained
     *                           (empty topic list — {@see
     *                           \App\AI\StructuredOutput\Schema\SortClassificationSchema::build()})
     */
    private function schemaTopicEnum(?StructuredOutputSchema $schema): ?array
    {
        $enum = $schema?->schema['properties']['BTOPIC']['enum'] ?? null;

        return is_array($enum) ? $enum : null;
    }

    /**
     * Lightweight self-check that the mock's own output actually satisfies
     * the schema it was asked to conform to — a schema-aware mock that never
     * validates itself could silently drift from the schema it is meant to
     * exercise. Deliberately shallow (required top-level keys only, no type/
     * enum re-validation): deep JSON-schema validation belongs to the real
     * provider integration tests, not this dev-only stub.
     *
     * @param array<string, mixed> $data
     */
    private function assertMatchesSchema(array $data, StructuredOutputSchema $schema): void
    {
        $required = $schema->schema['required'] ?? [];
        $missing = array_diff($required, array_keys($data));

        if ([] !== $missing) {
            throw new \LogicException(sprintf('TestProvider: mock output for schema "%s" is missing required key(s): %s', $schema->name, implode(', ', $missing)));
        }
    }

    /**
     * Mock task plan for the multi-task router (tools:plan prompt).
     *
     * A "summarize … and translate …" request yields a 2-node text chain
     * (summarize → translate → compose_reply) so E2E can verify the task cards
     * with deterministic, TTS-free streaming. Everything else returns a safe
     * single-node chat plan (executor then uses the legacy single-node path —
     * identical to a fallback, so existing tests are unaffected).
     *
     * Schema-aware: {@see \App\AI\StructuredOutput\Schema\TaskPlanSchema}
     * already matches this stub's natural shape (a root object, `strict:
     * false` for the open-ended `inputs`/`params`), so no field-level change
     * is needed here — only the self-validation of the required top-level
     * keys before returning.
     */
    private function mockTaskPlan(string $userContent, ?StructuredOutputSchema $schema): string
    {
        $data = json_decode($userContent, true);
        $text = is_array($data) ? strtolower((string) ($data['BTEXT'] ?? '')) : strtolower($userContent);

        if ($this->isSummarizeTranslateRequest($text)) {
            $plan = [
                'version' => 1,
                'language' => 'en',
                'reply_node' => 'n3',
                'tasks' => [
                    ['id' => 'n1', 'capability' => 'summarize', 'inputs' => ['text' => '$message.text']],
                    ['id' => 'n2', 'capability' => 'translate', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text'], 'params' => ['target' => 'de']],
                    ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n2'], 'inputs' => ['text' => '$n2.text']],
                ],
            ];
        } elseif ($this->isWebSearchPlanRequest($text)) {
            // web_search + chat plan — used by @webSearch E2E tests.
            $plan = [
                'version' => 1,
                'language' => 'en',
                'reply_node' => 'n2',
                'tasks' => [
                    ['id' => 'n1', 'capability' => 'web_search', 'inputs' => ['query' => '$message.text']],
                    ['id' => 'n2', 'capability' => 'chat', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text']],
                ],
            ];
        } else {
            $plan = [
                'version' => 1,
                'language' => 'en',
                'reply_node' => 'n1',
                'tasks' => [
                    ['id' => 'n1', 'capability' => 'chat', 'inputs' => ['text' => '$message.text']],
                ],
            ];
        }

        if (null !== $schema) {
            $this->assertMatchesSchema($plan, $schema);
        }

        return json_encode($plan, JSON_THROW_ON_ERROR);
    }

    /**
     * The two request shapes {@see mockTaskPlan()} expands into a multi-node
     * plan. Shared with the sort stub so its BMULTI vote and the plan it would
     * produce always agree — a vote of 0 makes TaskPlanExecutor skip planning
     * altogether, so a disagreement silently kills the DAG.
     */
    private function needsMultiStepPlan(string $text): bool
    {
        return $this->isSummarizeTranslateRequest($text) || $this->isWebSearchPlanRequest($text);
    }

    private function isSummarizeTranslateRequest(string $text): bool
    {
        return str_contains($text, 'summ') && str_contains($text, 'translat');
    }

    private function isWebSearchPlanRequest(string $text): bool
    {
        return str_contains($text, 'websearch:');
    }

    /**
     * Mock memory extraction: deterministic contract for E2E tests.
     *
     * The current user message may contain an explicit instruction of the
     * form `memorize: some_key = some value` — exactly that becomes a
     * `create` action (category `preferences`). Everything else returns no
     * actions so ordinary E2E chat turns never pollute the user's memory
     * list.
     *
     * Schema-aware: {@see \App\AI\StructuredOutput\Schema\MemoryExtractionSchema}
     * wraps the action list under a `memories` key (OpenAI-dialect structured
     * output and Anthropic tool-forcing both reject a bare top-level array),
     * so the schema-aware branch returns that envelope and fills in the
     * schema's nullable `memory_id`. Without a schema the stub keeps its
     * original bare-array shape — {@see
     * \App\Service\MemoryExtractionService::parseMemoriesFromResponse()}
     * accepts both via regex, so neither shape is a compatibility risk.
     */
    private function mockMemoryExtraction(string $userContent, ?StructuredOutputSchema $schema): string
    {
        $currentMessage = '';
        if (preg_match('/Current Message \(from the user\):\n(.*?)(?:\n\n|$)/s', $userContent, $m)) {
            $currentMessage = $m[1];
        }

        $memories = [];
        if (preg_match('/memorize:\s*([a-z0-9_]+)\s*=\s*([^\n]+)/i', $currentMessage, $m)) {
            $memories[] = [
                'action' => 'create',
                'memory_id' => null,
                'category' => 'preferences',
                'key' => strtolower($m[1]),
                'value' => trim($m[2]),
            ];
        }

        if (null !== $schema) {
            $wrapped = ['memories' => $memories];
            $this->assertMatchesSchema($wrapped, $schema);

            return json_encode($wrapped, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return json_encode($memories, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function detectLanguage(string $text, string $fallback = 'en'): string
    {
        if ('' === $text) {
            return $fallback;
        }

        $patterns = [
            'de' => '/\b(ich|und|der|die|das|ein|eine|ist|bitte|danke|erstelle|mache|kannst|hallo|wie|was|wer|wo|warum|nicht|auch|aber|oder|für|mit|von)\b/u',
            'fr' => '/\b(je|tu|il|elle|nous|vous|les|une|est|sont|avec|pour|dans|pas|merci|bonjour|oui|non|comment|pourquoi)\b/u',
            'es' => '/\b(yo|tú|él|ella|los|las|una|estoy|somos|para|con|por|hola|gracias|sí|cómo|qué|dónde|pero|también)\b/u',
            'it' => '/\b(io|tu|lui|lei|noi|gli|una|sono|siamo|per|con|ciao|grazie|come|cosa|dove|perché|anche|questo|quello)\b/u',
            'nl' => '/\b(ik|je|hij|zij|wij|het|een|zijn|hebben|voor|met|van|hallo|dank|hoe|wat|waar|waarom|niet|ook)\b/u',
            'pt' => '/\b(eu|tu|ele|ela|nós|uma|são|para|com|olá|obrigado|como|onde|porquê|também|este|esse)\b/u',
            'ru' => '/[а-яА-ЯёЁ]{3,}/u',
            'tr' => '/\b(ben|sen|bir|için|ile|merhaba|teşekkür|nasıl|neden|nerede|ama|veya|değil|var|yok|çok|bu|şu)\b/u',
            'sv' => '/\b(jag|du|han|hon|vi|det|ett|för|med|hej|tack|hur|vad|var|varför|inte|också|men|eller)\b/u',
        ];

        $scores = [];
        foreach ($patterns as $lang => $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                $scores[$lang] = count($matches[0]);
            }
        }

        if (empty($scores)) {
            return $fallback;
        }

        arsort($scores);

        return array_key_first($scores);
    }

    private function needsWebSearch(string $text): bool
    {
        return (bool) preg_match(
            '/\b(aktuell|current|news|wetter|weather|preis|price|heute|today|gestern|yesterday|2024|2025|2026|börse|stock|restaurant|öffnungszeiten|opening hours)\b/u',
            $text
        );
    }

    /**
     * Extract the list of valid topic slugs from the system prompt's
     * KEYLIST section (e.g. "general" | "mediamaker" | "coding").
     *
     * @return string[]
     */
    private function extractAvailableTopics(string $systemContent): array
    {
        if (preg_match_all('/"([^"]+)"/', $systemContent, $matches)) {
            $candidates = array_unique($matches[1]);

            return array_values(array_filter(
                $candidates,
                fn (string $t) => !in_array($t, ['de', 'en', 'it', 'es', 'fr', 'nl', 'pt', 'ru', 'sv', 'tr', 'image', 'video', 'audio', 'text_only', 'reference_images', '720p', '1080p', '4K'], true)
                    && !str_starts_with($t, 'tools:')
            ));
        }

        return ['general'];
    }

    /**
     * Classify the user text into a topic. Uses keyword matching against the
     * available topics and media-generation heuristics.
     *
     * @param string[] $availableTopics
     *
     * @return array{topic: string, media_type?: string, duration?: int, resolution?: string, input_mode?: string}
     */
    private function classifyTopic(string $text, array $data, array $availableTopics): array
    {
        $hasMediamaker = in_array('mediamaker', $availableTopics, true);

        if ($hasMediamaker) {
            $mediaResult = $this->detectMediaIntent($text, $data);
            if (null !== $mediaResult) {
                return $mediaResult;
            }
        }

        return ['topic' => $data['BTOPIC'] ?: 'general'];
    }

    /**
     * Detect media generation intent and return structured classification,
     * or null when the message is not a media request.
     *
     * @return array{topic: string, media_type: string, duration?: int, resolution?: string, input_mode?: string}|null
     */
    private function detectMediaIntent(string $text, array $data): ?array
    {
        $isVideoRequest = (bool) preg_match('/\b(video|film|clip|animation|movie)\b/u', $text);
        $isAudioRequest = (bool) preg_match('/\b(sprich|vorlesen|lies vor|speak|tts|text.to.speech|vertone|audio|aloud)\b/u', $text)
            || (bool) preg_match('/\bread\b.*\b(aloud|vor)\b/u', $text);
        $isImageGenRequest = (bool) preg_match('/\b(erstelle.*bild|generate.*image|create.*image|create.*picture|mache.*foto|draw|zeichne|illustr|render|design.*logo|bild.*erstellen|image.*generat)\b/u', $text);

        $hasImageAttachments = false;
        if (!empty($data['BATTACHED_FILES'])) {
            $hasImageAttachments = (bool) preg_match('/\b(jpg|jpeg|png|gif|webp)\b/i', $data['BATTACHED_FILES']);
        }
        if (!$hasImageAttachments && !empty($data['BFILETYPE'])) {
            $hasImageAttachments = (bool) preg_match('/^image\//i', $data['BFILETYPE']);
        }

        $isImageEditRequest = $hasImageAttachments && (bool) preg_match(
            '/\b(edit|bearbeit|combine|kombin|merge|blend|replace|ersetze|style|transform|mach.*daraus|put.*into|füge.*ein)\b/u',
            $text
        );

        if ($isVideoRequest) {
            $result = ['topic' => 'mediamaker', 'media_type' => 'video'];

            if (preg_match('/(\d+)\s*(?:sekund|second|sec|s\b)/u', $text, $m)) {
                $duration = (int) $m[1];
                $result['duration'] = max(4, min(8, $duration));
            }

            if (preg_match('/\b(720p?|1080p?|4k|uhd|hd|fullhd|full.hd)\b/ui', $text, $m)) {
                $result['resolution'] = $this->mapResolution($m[1]);
            }

            return $result;
        }

        if ($isAudioRequest) {
            return ['topic' => 'mediamaker', 'media_type' => 'audio'];
        }

        if ($isImageEditRequest) {
            return ['topic' => 'mediamaker', 'media_type' => 'image', 'input_mode' => 'reference_images'];
        }

        if ($isImageGenRequest) {
            return ['topic' => 'mediamaker', 'media_type' => 'image', 'input_mode' => 'text_only'];
        }

        return null;
    }

    private function mapResolution(string $raw): string
    {
        $key = strtolower(preg_replace('/[\s\-_]+/', '', $raw));

        return match ($key) {
            '720', '720p', 'hd' => '720p',
            '1080', '1080p', 'fhd', 'fullhd' => '1080p',
            '4k', 'uhd' => '4K',
            default => '1080p',
        };
    }

    /**
     * Mock search-query extraction: same logic as SearchQueryGenerator::fallbackExtraction
     * so integration tests (SearchQueryGeneratorTest) pass with TestProvider.
     */
    private function mockSearchQueryExtraction(string $text): string
    {
        $text = preg_replace('/^\/(search|web|google|find)\s+/i', '', $text);
        $text = trim($text);
        if (preg_match('/^(["\'])(.+)\1$/s', $text, $matches)) {
            $text = $matches[2];
        }

        return $text;
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        $result = $this->chat($messages, $options);
        if (isset($result['tool_calls'])) {
            foreach ($result['tool_calls'] as $index => $call) {
                $fn = is_array($call['function'] ?? null) ? $call['function'] : [];
                $arguments = (string) ($fn['arguments'] ?? '{}');
                $mid = (int) max(1, (int) ceil(strlen($arguments) / 2));
                $callback([
                    'type' => 'tool_call_delta',
                    'index' => $index,
                    'id' => $call['id'] ?? null,
                    'name' => $fn['name'] ?? null,
                    'arguments' => substr($arguments, 0, $mid),
                ]);
                $callback([
                    'type' => 'tool_call_delta',
                    'index' => $index,
                    'id' => null,
                    'name' => null,
                    'arguments' => substr($arguments, $mid),
                ]);
            }
            $callback(['type' => 'finish', 'finish_reason' => 'tool_calls']);

            return ['usage' => $result['usage']];
        }

        foreach (str_split($result['content'], 10) as $chunk) {
            $callback($chunk);
            usleep(50000);
        }

        return ['usage' => $result['usage']];
    }

    // EmbeddingProviderInterface
    public function embed(string $text, array $options = []): array
    {
        return [
            'embedding' => array_fill(0, 1024, 0.123),
            'usage' => [
                'prompt_tokens' => self::FAKE_TOKENS_PER_EMBED,
                'total_tokens' => self::FAKE_TOKENS_PER_EMBED,
            ],
        ];
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        $embeddings = [];
        foreach ($texts as $t) {
            $embeddings[] = $this->embed($t, $options)['embedding'];
        }

        $promptTokens = self::FAKE_TOKENS_PER_EMBED * count($texts);

        return [
            'embeddings' => $embeddings,
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'total_tokens' => $promptTokens,
            ],
        ];
    }

    public function getDimensions(string $model): int
    {
        return 1024;
    }

    // VisionProviderInterface
    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        return "Test image description: A test image at {$imageUrl}";
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return 'Extracted text from test image';
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        return ['similarity' => 0.95, 'differences' => 'Test comparison'];
    }

    // ImageGenerationProviderInterface
    public function generateImage(string $prompt, array $options = []): array
    {
        $label = empty($options['images']) ? 'Test+Image' : 'Test+Pic2Pic';

        return [[
            'url' => 'https://via.placeholder.com/1024x1024?text='.$label,
            'revised_prompt' => $prompt,
        ]];
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        return array_fill(0, $count, 'https://via.placeholder.com/1024x1024');
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        return 'https://via.placeholder.com/1024x1024?text=Edited';
    }

    // SpeechToTextProviderInterface
    public function transcribe(string $audioPath, array $options = []): array
    {
        return [
            'text' => 'Test transcription',
            'language' => 'en',
            'duration' => 10.0,
        ];
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        return "Test audio translation to {$targetLang}";
    }

    // TextToSpeechProviderInterface
    public function synthesize(string $text, array $options = []): string
    {
        // Write a real (silent) MP3 frame into the upload dir root —
        // AiFacade::moveToUserPath() expects the returned filename to
        // exist there so it can move it into the user path. Returning a
        // non-existent path made every voice-reply turn on the test
        // provider fail silently in the move step (issue #1070 E2E).
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0775, true);
        }

        $filename = 'tts_test_'.uniqid().'.mp3';
        // Single silent MPEG-1 Layer III frame (128 kbit/s, 44.1 kHz).
        $frame = "\xFF\xFB\x90\x64".str_repeat("\x00", 413);
        file_put_contents($this->uploadDir.'/'.$filename, $frame);

        return $filename;
    }

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        yield 'fake-audio-chunk-1';
        yield 'fake-audio-chunk-2';
    }

    public function getStreamContentType(array $options = []): string
    {
        return 'audio/mpeg';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function getVoices(): array
    {
        return [['id' => 'test', 'name' => 'Test Voice', 'language' => 'en']];
    }

    // FileAnalysisProviderInterface
    public function analyzeFile(string $filePath, string $fileType, array $options = []): array
    {
        return [
            'text' => 'Test file content',
            'summary' => 'Test summary',
            'metadata' => ['pages' => 1],
        ];
    }

    public function askAboutFile(string $filePath, string $question): string
    {
        return "Test answer to: {$question}";
    }
}
