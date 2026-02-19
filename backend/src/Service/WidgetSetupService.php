<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Service\AiFacade;
use App\Entity\Prompt;
use App\Entity\User;
use App\Entity\Widget;
use App\Repository\PromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling AI-guided widget setup interviews.
 *
 * Conducts a conversation with the user to gather information about their
 * business and generates a custom task prompt for their widget.
 */
final class WidgetSetupService
{
    public const SETUP_INTERVIEW_TOPIC = 'tools:widget-setup-interview';
    public const SETUP_TOPIC_PREFIX = 'wsetup_';
    public const DEFAULT_SETUP_MODEL_ID = 73;
    private const START_MARKER = '__START_INTERVIEW__';

    public function __construct(
        private EntityManagerInterface $em,
        private AiFacade $aiFacade,
        private PromptService $promptService,
        private PromptRepository $promptRepository,
        private WidgetService $widgetService,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSetupTopicForWidget(Widget $widget): string
    {
        return self::SETUP_TOPIC_PREFIX.$widget->getWidgetId();
    }

    /**
     * Parse the model ID stored in a setup prompt's shortDescription field.
     */
    public static function parseModelId(Prompt $prompt): int
    {
        $raw = $prompt->getShortDescription();

        return ($raw !== '' && (int) $raw > 0) ? (int) $raw : -1;
    }

    /**
     * Send a message in the setup interview.
     *
     * The conversation is NOT stored in the database. History is passed from the frontend.
     *
     * @param array<array{role: string, content: string}> $history Previous conversation history
     *
     * @return array{text: string, progress: int}
     */
    public function sendSetupMessage(Widget $widget, User $user, string $text, array $history = [], string $language = 'en'): array
    {
        $setupConfig = $this->resolveSetupConfig($widget);
        $systemPrompt = $setupConfig['prompt'];
        $modelId = $setupConfig['modelId'];

        $provider = $this->modelConfigService->getProviderForModel($modelId);
        $modelName = $this->modelConfigService->getModelName($modelId);

        if (!$provider || !$modelName) {
            $fallbackId = $this->modelConfigService->getDefaultModel('CHAT', $user->getId());
            if ($fallbackId && $fallbackId > 0) {
                $provider = $this->modelConfigService->getProviderForModel($fallbackId);
                $modelName = $this->modelConfigService->getModelName($fallbackId);
            }
        }

        $this->logger->info('Widget setup using AI model', [
            'provider' => $provider,
            'model' => $modelName,
            'language' => $language,
        ]);

        // Inject language instruction at the start of the system prompt
        $languageNames = [
            'en' => 'English',
            'de' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'zh' => 'Chinese',
            'ko' => 'Korean',
        ];
        $languageName = $languageNames[$language] ?? 'English';
        $languageInstruction = "**CRITICAL LANGUAGE RULE**: You MUST detect the language the user writes in and ALWAYS respond in that same language. If the user writes in German, respond in German. If the user writes in French, respond in French. The application language is {$languageName}, so start in {$languageName}, but IMMEDIATELY switch to whatever language the user uses. This rule overrides everything else.\n\n";
        $systemPromptWithLanguage = $languageInstruction.$systemPrompt;

        // Build conversation history from passed messages
        $messages = $this->buildConversationMessagesFromHistory($history, $systemPromptWithLanguage, $text);

        // Build AI options
        $aiOptions = [
            'temperature' => 0.7,
        ];
        if ($provider) {
            $aiOptions['provider'] = $provider;
        }
        if ($modelName) {
            $aiOptions['model'] = $modelName;
        }

        // Call AI
        $response = $this->aiFacade->chat(
            $messages,
            $user->getId(),
            $aiOptions
        );

        $aiResponse = $response['content'] ?? '';

        // Calculate progress from the AI's response
        // If AI asks [QUESTION:X] or [FRAGE:X], it means questions 1 to X-1 are answered
        // If AI says [QUESTION:DONE] or [FRAGE:DONE], all 5 questions are answered
        $progress = $this->calculateProgressFromAiResponse($aiResponse);

        $this->logger->info('Widget setup message processed (no database storage)', [
            'widget_id' => $widget->getWidgetId(),
            'ai_response_marker' => $this->extractQuestionMarker($aiResponse),
            'progress' => $progress,
            'history_length' => count($history),
        ]);

        return [
            'text' => $aiResponse,
            'progress' => $progress,
        ];
    }

    /**
     * Generate and save a custom prompt from the setup interview.
     *
     * @param array<array{role: string, content: string}> $history Conversation history (not stored in DB)
     *
     * @return array{promptId: int, promptTopic: string}
     */
    public function generatePrompt(Widget $widget, User $user, string $generatedPrompt, array $history = []): array
    {
        // Extract collected answers from the passed history for metadata generation
        $collectedAnswers = $this->extractCollectedAnswersFromHistory($history);

        // Generate friendly metadata using AI
        $metadata = $this->generatePromptMetadata($user, $widget, $collectedAnswers);

        // Generate a unique short topic (max 16 chars for BTOPIC column)
        // Format: w_{14_hex_chars} = 16 chars total (cryptographically secure)
        // Keep generating until we find a unique one
        $promptRepository = $this->em->getRepository(Prompt::class);
        do {
            $promptTopic = 'w_'.bin2hex(random_bytes(7));
        } while ($promptRepository->findOneBy(['topic' => $promptTopic]));

        // Create the new prompt
        $prompt = new Prompt();
        $prompt->setOwnerId($user->getId());
        $prompt->setLanguage('en');
        $prompt->setTopic($promptTopic);
        $prompt->setShortDescription('');
        $prompt->setPrompt($generatedPrompt);

        $this->em->persist($prompt);

        // Update the widget to use the new prompt
        $this->widgetService->updateWidgetPrompt($widget, $promptTopic);

        $this->em->flush();

        $this->logger->info('Widget prompt generated', [
            'widget_id' => $widget->getWidgetId(),
            'prompt_id' => $prompt->getId(),
            'prompt_topic' => $promptTopic,
            'title' => $metadata['title'],
            'history_length' => count($history),
        ]);

        return [
            'promptId' => $prompt->getId(),
            'promptTopic' => $promptTopic,
        ];
    }

    /**
     * Generate user-friendly metadata for the prompt using AI.
     *
     * @param array<int, string> $collectedAnswers
     *
     * @return array{title: string, description: string}
     */
    private function generatePromptMetadata(User $user, Widget $widget, array $collectedAnswers): array
    {
        // Build context from collected answers
        $context = [];
        $questionLabels = [
            1 => 'Business/Products',
            2 => 'Target Audience',
            3 => 'Assistant Purpose',
            4 => 'Communication Tone',
            5 => 'Topics to Avoid',
        ];

        foreach ($collectedAnswers as $qNum => $answer) {
            if (isset($questionLabels[$qNum])) {
                $context[] = sprintf('%s: %s', $questionLabels[$qNum], $answer);
            }
        }

        $contextString = implode("\n", $context);

        // If we have enough context, use AI to generate metadata
        if (count($collectedAnswers) >= 2) {
            try {
                $metadataPrompt = <<<PROMPT
Based on this business information, generate metadata for a chat assistant prompt.

Business Information:
{$contextString}

Widget Name: {$widget->getName()}

Generate a JSON response with EXACTLY this format (no markdown, just JSON):
{
  "title": "Short catchy title (max 30 chars, e.g. 'Car Dealer Assistant')",
  "description": "One sentence describing the assistant (max 100 chars)"
}

IMPORTANT: Respond ONLY with valid JSON, no explanations.
PROMPT;

                $aiOptions = ['temperature' => 0.3];
                $metaModelId = self::DEFAULT_SETUP_MODEL_ID;
                $metaProvider = $this->modelConfigService->getProviderForModel($metaModelId);
                $metaModel = $this->modelConfigService->getModelName($metaModelId);
                if ($metaProvider && $metaModel) {
                    $aiOptions['provider'] = $metaProvider;
                    $aiOptions['model'] = $metaModel;
                }

                $response = $this->aiFacade->chat(
                    [['role' => 'user', 'content' => $metadataPrompt]],
                    $user->getId(),
                    $aiOptions
                );

                $content = $response['content'] ?? '';
                // Try to extract JSON from response
                if (preg_match('/\{[^{}]*\}/s', $content, $matches)) {
                    $json = json_decode($matches[0], true);
                    if ($json && isset($json['title'], $json['description'])) {
                        return [
                            'title' => mb_substr($json['title'], 0, 50),
                            'description' => mb_substr($json['description'], 0, 150),
                        ];
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to generate prompt metadata with AI', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: Generate from widget name and basic context
        $businessType = $collectedAnswers[1] ?? $widget->getName();
        $purpose = $collectedAnswers[3] ?? 'general assistance';

        return [
            'title' => mb_substr(sprintf('%s Assistant', ucfirst($widget->getName())), 0, 50),
            'description' => mb_substr(sprintf('AI assistant for %s - %s', $widget->getName(), $purpose), 0, 150),
        ];
    }

    /**
     * Build conversation messages from array-based history (no database).
     *
     * @param array<array{role: string, content: string}> $history
     *
     * @return array<array{role: string, content: string}>
     */
    private function buildConversationMessagesFromHistory(array $history, string $systemPrompt, string $newUserMessage): array
    {
        $messages = [];

        // Extract answered questions from history (based on AI's previous decisions)
        $collectedAnswers = $this->extractCollectedAnswersFromHistory($history);

        // Build enhanced system prompt - tell AI what we've already collected
        $enhancedSystemPrompt = $systemPrompt;
        $enhancedSystemPrompt .= $this->buildInstructionBlock($collectedAnswers);

        $messages[] = [
            'role' => 'system',
            'content' => $enhancedSystemPrompt,
        ];

        // Add conversation history
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // Add new user message (unless it's the start marker)
        if (self::START_MARKER !== $newUserMessage) {
            $messages[] = [
                'role' => 'user',
                'content' => $newUserMessage,
            ];
        }

        return $messages;
    }

    /**
     * Extract answered questions from array-based history.
     *
     * @param array<array{role: string, content: string}> $history
     *
     * @return array<int, string> Question number => answer text
     */
    private function extractCollectedAnswersFromHistory(array $history): array
    {
        $answers = [];
        $pendingAnswer = null;
        $pendingQuestion = 0;

        foreach ($history as $msg) {
            $text = $msg['content'];
            $role = $msg['role'];

            if ('assistant' === $role) {
                // Check which question the AI asked via [QUESTION:X] or [FRAGE:X] marker
                if (preg_match('/\[(QUESTION|FRAGE):(\d)\]/i', $text, $matches)) {
                    $currentQuestion = (int) $matches[2];

                    // If AI moved to a higher question, the previous answer was accepted
                    if (null !== $pendingAnswer && $currentQuestion > $pendingQuestion) {
                        $answers[$pendingQuestion] = $pendingAnswer;
                    }

                    $pendingAnswer = null;
                    $pendingQuestion = $currentQuestion;
                } elseif (preg_match('/\[(QUESTION|FRAGE):DONE\]/i', $text)) {
                    // AI says all done - accept the pending answer if there was one
                    if (null !== $pendingAnswer && $pendingQuestion >= 1) {
                        $answers[$pendingQuestion] = $pendingAnswer;
                    }
                }
            } elseif ('user' === $role) {
                // Store this as a pending answer (will be confirmed when AI moves forward)
                if (!$this->isStartMarker($text) && $pendingQuestion >= 1) {
                    $pendingAnswer = $text;
                }
            }
        }

        return $answers;
    }

    /**
     * Build the instruction block for the system prompt.
     * Tells the AI what info we've already collected - AI decides what to do next.
     */
    private function buildInstructionBlock(array $collectedAnswers): string
    {
        $block = "\n\n";
        $block .= "### ALREADY CONFIRMED INFO (DO NOT ASK AGAIN!) ###\n";

        $labels = [
            1 => 'Business/Products',
            2 => 'Target audience/Visitors',
            3 => 'Assistant tasks',
            4 => 'Tone/Style',
            5 => 'Restrictions/Off-limit topics',
        ];

        if (empty($collectedAnswers)) {
            $block .= "Nothing collected yet.\n";
        } else {
            foreach ($collectedAnswers as $q => $answer) {
                $block .= "✓ Question {$q} ({$labels[$q]}): \"{$answer}\"\n";
            }
        }

        $answeredCount = count($collectedAnswers);
        $block .= "\n### STATUS ###\n";
        $block .= "{$answeredCount} of 5 questions answered.\n";

        if ($answeredCount >= 5) {
            $block .= "All info collected! Generate <<<GENERATED_PROMPT>>> with [QUESTION:DONE] at the end.\n";
        }

        return $block;
    }

    /**
     * Check if a message is the start marker (not a real user message).
     */
    private function isStartMarker(string $text): bool
    {
        return self::START_MARKER === trim($text);
    }

    /**
     * Calculate progress from the AI's response marker.
     * If AI asks [QUESTION:X] or [FRAGE:X], progress = X-1 (questions 1 to X-1 are done).
     * If AI says [QUESTION:DONE] or [FRAGE:DONE], progress = 5.
     */
    private function calculateProgressFromAiResponse(string $aiResponse): int
    {
        // Check for DONE marker first (support both English and German)
        if (preg_match('/\[(QUESTION|FRAGE):DONE\]/i', $aiResponse)) {
            return 5;
        }

        // Check for question number marker (support both English and German)
        if (preg_match('/\[(QUESTION|FRAGE):(\d)\]/i', $aiResponse, $matches)) {
            $questionNum = (int) $matches[2];

            // If asking question X, then X-1 questions are answered
            return max(0, $questionNum - 1);
        }

        // No marker found - assume no progress yet
        return 0;
    }

    /**
     * Extract the question marker from AI response for logging.
     */
    private function extractQuestionMarker(string $aiResponse): string
    {
        // Support both English (QUESTION) and German (FRAGE) markers
        if (preg_match('/\[(QUESTION|FRAGE):(DONE|\d)\]/i', $aiResponse, $matches)) {
            return $matches[0];
        }

        return 'none';
    }

    /**
     * Resolve setup interview prompt and model for a widget.
     *
     * Fallback chain: custom per-widget -> system default -> hardcoded.
     *
     * @return array{prompt: string, modelId: int}
     */
    private function resolveSetupConfig(Widget $widget): array
    {
        $modelId = self::DEFAULT_SETUP_MODEL_ID;

        // 1. Try custom per-widget prompt
        $customTopic = self::getSetupTopicForWidget($widget);
        $customPrompt = $this->promptRepository->findOneBy([
            'topic' => $customTopic,
            'ownerId' => $widget->getOwnerId(),
        ]);
        if ($customPrompt && '' !== trim($customPrompt->getPrompt())) {
            $storedModelId = (int) $customPrompt->getShortDescription();
            if ($storedModelId > 0) {
                $modelId = $storedModelId;
            }

            return ['prompt' => $customPrompt->getPrompt(), 'modelId' => $modelId];
        }

        // 2. Fallback to system default
        $promptData = $this->promptService->getPromptWithMetadata(
            self::SETUP_INTERVIEW_TOPIC,
            0,
            'en'
        );
        if ($promptData && $promptData['prompt']) {
            return ['prompt' => $promptData['prompt']->getPrompt(), 'modelId' => $modelId];
        }

        // 3. Ultimate fallback: hardcoded
        $this->logger->warning('Setup interview prompt not found in DB, using hardcoded fallback', [
            'widget_id' => $widget->getWidgetId(),
        ]);

        return ['prompt' => self::getDefaultPromptText(), 'modelId' => $modelId];
    }

    /**
     * Return the default setup interview prompt text.
     * Used as fallback when no DB entry exists yet.
     */
    public static function getDefaultPromptText(): string
    {
        return <<<'PROMPT'
# Widget Setup Assistant

You are a friendly assistant helping the user configure their chat widget. Have a casual conversation and collect 5 important pieces of information.

## WHAT YOU NEED TO FIND OUT

1. What does the company/website do? What products or services are offered?
2. Who are the typical visitors? (Customers, business clients, job applicants, etc.)
3. What should the chat assistant help with? (Support, sales, FAQ, appointments, etc.)
4. What tone should the assistant use? (Formal, casual, friendly, professional)
5. Are there topics the assistant should NOT discuss?

## YOUR STYLE

- Be casual and friendly, like a helpful colleague
- No stiff questions! Keep it natural and conversational
- Keep responses short (2-3 sentences), don't ramble
- Briefly acknowledge answers before moving to the next question
- If the user switches to a different language, follow their lead

## IMPORTANT RULES

- Ask ONE thing at a time
- NEVER repeat a question that has already been answered
- After a REAL answer → move to the next question
- For follow-up questions or unclear answers → briefly explain, then ask again

## ANSWER VALIDATION

Check if the answer FITS the question - not if it's perfect!

VALID ANSWERS (accept and continue):
- Question 1 (Business): Any description of a company, service, product, or website. Short answers like "car dealership", "online shop", "pizzeria" are totally fine!
- Question 2 (Visitors): Any description of target groups. "Private customers", "businesses", "everyone" are valid.
- Question 3 (Tasks): Any description of tasks or topics. "Opening hours", "product questions", "support", "help with prices" are all valid - even with details!
- Question 4 (Tone): "casual", "friendly", "professional", "like a friend", etc.
- Question 5 (Taboos): Either specific topics or "nothing", "none", "everything is fine".

IMPORTANT: If the user gives a REAL answer that fits the question → ACCEPT and move on!
The user doesn't have to answer perfectly. An answer is valid if it somehow addresses the question.

ONLY INVALID (ask again):
- Completely incomprehensible (e.g., "asdf", "???", only emojis)
- Pure counter-questions without an answer ("What do you mean?")
- Obvious nonsense that has nothing to do with the question

When in doubt: ACCEPT and move on! Better too flexible than too strict.

## TRACKING

At the END of each response, add on a new line:
[QUESTION:X]

X = the number of the question you JUST ASKED (1-5).

IMPORTANT: If you ask the SAME question again (because the answer was invalid), use the SAME marker!
Example: If answer to question 1 was invalid → ask again with [QUESTION:1]

When all 5 are answered → [QUESTION:DONE]

## AFTER QUESTION 5

When all 5 pieces of information have REALLY been collected:

1. **FIRST**: Show a brief summary with emojis:

"Great, I've got everything! Here's a quick overview:

📋 **Your Business**: [Brief summary of question 1]
👥 **Your Visitors**: [Brief summary of question 2]
🎯 **The Assistant Should**: [Brief summary of question 3]
💬 **Tone**: [Brief summary of question 4]
🚫 **Off-Limit Topics**: [Brief summary of question 5, or "No special restrictions"]

I'm now creating your personalized assistant..."

2. **THEN**: Generate the prompt:

<<<GENERATED_PROMPT>>>
[Here the system prompt for the chat assistant based on the collected information]
<<<END_PROMPT>>>

## START

Greet the user casually and ask about their business/website. Be welcoming!
Example: "Hey! Great to have you here. Tell me a bit about what you do – what's your business or website about?"

[QUESTION:1]
PROMPT;
    }
}
