<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Service\AiFacade;
use App\Entity\Prompt;
use App\Entity\User;
use App\Entity\Widget;
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
    public const SETUP_INTERVIEW_TOPIC = 'widget-setup-interview';
    private const START_MARKER = '__START_INTERVIEW__';

    public function __construct(
        private EntityManagerInterface $em,
        private AiFacade $aiFacade,
        private PromptService $promptService,
        private WidgetService $widgetService,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
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
        // Get the setup interview prompt
        $promptData = $this->promptService->getPromptWithMetadata(
            self::SETUP_INTERVIEW_TOPIC,
            $user->getId(),
            'en'
        );

        if (!$promptData || !$promptData['prompt']) {
            $this->logger->error('Widget setup interview prompt not found', [
                'topic' => self::SETUP_INTERVIEW_TOPIC,
                'user_id' => $user->getId(),
            ]);
            throw new \RuntimeException('Setup interview prompt not configured. Please run database fixtures.');
        }

        $systemPrompt = $promptData['prompt']->getPrompt();

        // Use a cheap, reliable model for the setup interview
        // Priority: OpenAI gpt-4o-mini (ID 73), fallback to Groq Llama 3.3 70b (ID 9)
        $provider = null;
        $modelName = null;

        // Try OpenAI gpt-4o-mini first ($0.15/1M in, $0.60/1M out) - most reliable
        $openaiProvider = $this->modelConfigService->getProviderForModel(73);
        $openaiModel = $this->modelConfigService->getModelName(73);

        if ($openaiProvider && $openaiModel) {
            $provider = $openaiProvider;
            $modelName = $openaiModel;
        } else {
            // Fallback to Groq Llama 3.3 70b ($0.59/1M in, $0.79/1M out)
            $groqProvider = $this->modelConfigService->getProviderForModel(9);
            $groqModel = $this->modelConfigService->getModelName(9);

            if ($groqProvider && $groqModel) {
                $provider = $groqProvider;
                $modelName = $groqModel;
            } else {
                // Last resort: use user's default model
                $modelId = $this->modelConfigService->getDefaultModel('CHAT', $user->getId());
                if ($modelId && $modelId > 0) {
                    $provider = $this->modelConfigService->getProviderForModel($modelId);
                    $modelName = $this->modelConfigService->getModelName($modelId);
                }
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
        $languageInstruction = "**LANGUAGE SETTING**: The user's application is set to {$languageName}. Start the conversation in {$languageName}. Only switch languages if the user explicitly requests a different language or starts writing in a different language.\n\n";
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
        $prompt->setShortDescription($metadata['description']);
        $prompt->setPrompt($generatedPrompt);
        $prompt->setSelectionRules($metadata['selectionRules']);

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
     * @return array{title: string, description: string, selectionRules: string}
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
  "description": "One sentence describing the assistant (max 100 chars)",
  "selectionRules": "When to use this prompt, e.g. 'For customer inquiries about cars, pricing, test drives, and financing options'"
}

IMPORTANT: Respond ONLY with valid JSON, no explanations.
PROMPT;

                // Use reliable model for metadata generation (gpt-4o-mini)
                $aiOptions = ['temperature' => 0.3];
                $openaiProvider = $this->modelConfigService->getProviderForModel(73);
                $openaiModel = $this->modelConfigService->getModelName(73);
                if ($openaiProvider && $openaiModel) {
                    $aiOptions['provider'] = $openaiProvider;
                    $aiOptions['model'] = $openaiModel;
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
                    if ($json && isset($json['title'], $json['description'], $json['selectionRules'])) {
                        return [
                            'title' => mb_substr($json['title'], 0, 50),
                            'description' => mb_substr($json['description'], 0, 150),
                            'selectionRules' => mb_substr($json['selectionRules'], 0, 500),
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
            'selectionRules' => mb_substr(sprintf('Use for customer inquiries on %s website', $widget->getName()), 0, 500),
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
}
