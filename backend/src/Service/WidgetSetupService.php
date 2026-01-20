<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Service\AiFacade;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Entity\User;
use App\Entity\Widget;
use App\Repository\ChatRepository;
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
        private ChatRepository $chatRepository,
        private WidgetService $widgetService,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Send a message in the setup interview chat.
     *
     * @return array{chatId: int, messageId: int, text: string}
     */
    public function sendSetupMessage(Widget $widget, User $user, string $text, ?int $chatId = null): array
    {
        // Get or create chat for this setup session
        $chat = $chatId ? $this->chatRepository->find($chatId) : null;

        if (!$chat) {
            $chat = new Chat();
            $chat->setUserId($user->getId());
            $chat->setTitle('Widget Setup: '.$widget->getName());
            $this->em->persist($chat);
            $this->em->flush();
        }

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
        ]);

        // Build conversation history
        $messages = $this->buildConversationMessages($chat, $systemPrompt, $text);

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

        // Save user message (unless it's the start marker)
        if (self::START_MARKER !== $text) {
            $userMessage = new Message();
            $userMessage->setUserId($user->getId());
            $userMessage->setChat($chat);
            $userMessage->setDirection('IN');
            $userMessage->setMessageType('WEB');
            $userMessage->setText($text);
            $userMessage->setTrackingId(0);
            $this->em->persist($userMessage);
        }

        // Save AI response
        $aiMessage = new Message();
        $aiMessage->setUserId($user->getId());
        $aiMessage->setChat($chat);
        $aiMessage->setDirection('OUT');
        $aiMessage->setMessageType('WEB');
        $aiMessage->setText($aiResponse);
        $aiMessage->setTrackingId(0);
        $this->em->persist($aiMessage);

        $this->em->flush();

        // Calculate progress from the AI's response
        // If AI asks [FRAGE:X], it means questions 1 to X-1 are answered
        // If AI says [FRAGE:DONE], all 5 questions are answered
        $progress = $this->calculateProgressFromAiResponse($aiResponse);

        // Debug logging
        $this->logger->info('Widget setup progress calculated', [
            'widget_id' => $widget->getWidgetId(),
            'chat_id' => $chat->getId(),
            'ai_response_marker' => $this->extractQuestionMarker($aiResponse),
            'progress' => $progress,
        ]);

        $this->logger->info('Widget setup message processed', [
            'widget_id' => $widget->getWidgetId(),
            'chat_id' => $chat->getId(),
            'message_id' => $aiMessage->getId(),
            'progress' => $progress,
        ]);

        return [
            'chatId' => $chat->getId(),
            'messageId' => $aiMessage->getId(),
            'text' => $aiResponse,
            'progress' => $progress,
        ];
    }

    /**
     * Generate and save a custom prompt from the setup interview.
     *
     * @return array{promptId: int, promptTopic: string}
     */
    public function generatePrompt(Widget $widget, User $user, string $generatedPrompt, ?int $chatId = null): array
    {
        // Get collected answers from chat history for metadata generation
        $collectedAnswers = [];
        if ($chatId) {
            $chat = $this->chatRepository->find($chatId);
            if ($chat) {
                $historyMessages = $this->em->getRepository(Message::class)->findBy(
                    ['chat' => $chat],
                    ['id' => 'ASC']
                );
                $collectedAnswers = $this->extractCollectedAnswers($historyMessages);
            }
        }

        // Generate friendly metadata using AI
        $metadata = $this->generatePromptMetadata($user, $widget, $collectedAnswers);

        // Generate a unique but readable topic
        $promptTopic = sprintf('widget-%s-%d', $this->slugify($metadata['title']), time());

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
     * Convert a string to a URL-friendly slug.
     */
    private function slugify(string $text): string
    {
        // Transliterate
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        // Remove non-alphanumeric
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        // Trim dashes
        $text = trim($text, '-');

        // Limit length
        return mb_substr($text, 0, 30) ?: 'custom';
    }

    /**
     * Build the conversation messages array for the AI.
     * The AI decides if the user's answer is valid and what question to ask next.
     *
     * @return array<array{role: string, content: string}>
     */
    private function buildConversationMessages(Chat $chat, string $systemPrompt, string $newUserMessage): array
    {
        $messages = [];

        // Get conversation history
        $historyMessages = $this->em->getRepository(Message::class)->findBy(
            ['chat' => $chat],
            ['id' => 'ASC']
        );

        // Extract answered questions from history (based on AI's previous decisions)
        // An answer is only "collected" if the AI moved to the next question
        $collectedAnswers = $this->extractCollectedAnswers($historyMessages);

        // Build enhanced system prompt - tell AI what we've already collected
        // The AI will decide if the NEW user message is valid
        $enhancedSystemPrompt = $systemPrompt;
        $enhancedSystemPrompt .= $this->buildInstructionBlock($collectedAnswers);

        $messages[] = [
            'role' => 'system',
            'content' => $enhancedSystemPrompt,
        ];

        // Add conversation history
        foreach ($historyMessages as $msg) {
            $role = 'IN' === $msg->getDirection() ? 'user' : 'assistant';
            $messages[] = [
                'role' => $role,
                'content' => $msg->getText(),
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
     * Find the last question number asked by the AI.
     *
     * @param Message[] $historyMessages
     */
    private function getLastAskedQuestion(array $historyMessages): int
    {
        $lastAsked = 0;

        foreach ($historyMessages as $msg) {
            if ('OUT' === $msg->getDirection()) {
                // Match the [FRAGE:X] marker at the end of AI responses
                if (preg_match('/\[FRAGE:(\d)\]/i', $msg->getText(), $matches)) {
                    $questionNum = (int) $matches[1];
                    if ($questionNum >= 1 && $questionNum <= 5) {
                        $lastAsked = $questionNum;
                    }
                }
            }
        }

        return $lastAsked;
    }

    /**
     * Extract answered questions and their answers from conversation history.
     * An answer is only considered "collected" if the AI moved to a HIGHER question.
     *
     * @param Message[] $historyMessages
     *
     * @return array<int, string> Question number => answer text
     */
    private function extractCollectedAnswers(array $historyMessages): array
    {
        $answers = [];
        $pendingAnswer = null;
        $pendingQuestion = 0;

        foreach ($historyMessages as $msg) {
            $text = $msg->getText();
            $direction = $msg->getDirection();

            if ('OUT' === $direction) {
                // Check which question the AI asked via [FRAGE:X] marker
                if (preg_match('/\[FRAGE:(\d)\]/i', $text, $matches)) {
                    $currentQuestion = (int) $matches[1];

                    // If AI moved to a higher question, the previous answer was accepted
                    if (null !== $pendingAnswer && $currentQuestion > $pendingQuestion) {
                        $answers[$pendingQuestion] = $pendingAnswer;
                    }

                    $pendingAnswer = null;
                    $pendingQuestion = $currentQuestion;
                } elseif (preg_match('/\[FRAGE:DONE\]/i', $text)) {
                    // AI says all done - accept the pending answer if there was one
                    if (null !== $pendingAnswer && $pendingQuestion >= 1) {
                        $answers[$pendingQuestion] = $pendingAnswer;
                    }
                }
            } elseif ('IN' === $direction) {
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
        $block .= "### BEREITS BESTÄTIGTE INFOS (NICHT NOCHMAL FRAGEN!) ###\n";

        $labels = [
            1 => 'Business/Produkte',
            2 => 'Zielgruppe/Besucher',
            3 => 'Aufgaben des Assistenten',
            4 => 'Ton/Stil',
            5 => 'Einschränkungen/Tabu-Themen',
        ];

        if (empty($collectedAnswers)) {
            $block .= "Noch nichts gesammelt.\n";
        } else {
            foreach ($collectedAnswers as $q => $answer) {
                $block .= "✓ Frage {$q} ({$labels[$q]}): \"{$answer}\"\n";
            }
        }

        $answeredCount = count($collectedAnswers);
        $block .= "\n### STATUS ###\n";
        $block .= "{$answeredCount} von 5 Fragen beantwortet.\n";

        if ($answeredCount >= 5) {
            $block .= "Alle Infos da! Generiere <<<GENERATED_PROMPT>>> mit [FRAGE:DONE] am Ende.\n";
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
     * If AI asks [FRAGE:X], progress = X-1 (questions 1 to X-1 are done).
     * If AI says [FRAGE:DONE], progress = 5.
     */
    private function calculateProgressFromAiResponse(string $aiResponse): int
    {
        // Check for DONE marker first
        if (preg_match('/\[FRAGE:DONE\]/i', $aiResponse)) {
            return 5;
        }

        // Check for question number marker
        if (preg_match('/\[FRAGE:(\d)\]/i', $aiResponse, $matches)) {
            $questionNum = (int) $matches[1];

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
        if (preg_match('/\[FRAGE:(DONE|\d)\]/i', $aiResponse, $matches)) {
            return $matches[0];
        }

        return 'none';
    }
}
