<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\Plan\TaskPlanValidator;
use Psr\Log\LoggerInterface;

/**
 * Turns an inbound message into a validated {@see TaskPlan} using the planner
 * model (DEFAULTMODEL.PLAN, falling back to SORT).
 *
 * Robustness is the whole point: ANY problem — missing prompt, provider error,
 * non-JSON output, schema-invalid plan — degrades to a safe single-`chat` plan
 * so a turn is never broken by the planner. Custom user topics are preserved by
 * carrying the topic id into the fallback chat node.
 *
 * NOTE: this service decides the plan only. It never resolves or calls the
 * per-capability execution models (the migration principle).
 */
final readonly class TaskPlanner
{
    /** Human-readable catalogue injected into the planner prompt ([CAPABILITYLIST]). */
    private const CAPABILITY_DESCRIPTIONS = [
        'extract_text' => 'Extract text from an attached document or audio file (no model choice needed).',
        'chat' => 'Answer with text. Use params.topic_id to bind a specific task topic/system prompt.',
        'summarize' => 'Summarize provided text.',
        'translate' => 'Translate provided text into a target language (params.target).',
        'rag_query' => 'Answer using the user knowledge base (retrieval-augmented).',
        'web_search' => 'Search the web for current information.',
        'file_analysis' => 'Analyze/describe/OCR an attached image or document and answer about it.',
        'image_generation' => 'Generate or edit an image from a prompt and/or reference images.',
        'video_generation' => 'Generate a video clip (params.duration, params.resolution).',
        'text2sound' => 'Synthesize speech/audio from text (params.format, e.g. mp3).',
        'document_generation' => 'Generate an Office document (CSV/XLSX/DOCX/PPTX).',
        'compose_reply' => 'Assemble final reply: text + N file attachments from other nodes.',
    ];

    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private TaskPlanValidator $validator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Produce a task plan for the given message. Never throws — always returns a
     * usable plan (real or single-`chat` fallback).
     *
     * @param array<int, Message> $conversationHistory oldest-first thread
     */
    public function plan(Message $message, array $conversationHistory = [], ?int $userId = null): TaskPlanResult
    {
        $language = $message->getLanguage() ?: 'en';

        $promptRow = $this->promptRepository->findByTopic('tools:plan', 0);
        if (!$promptRow) {
            $this->logger->warning('TaskPlanner: tools:plan prompt missing, falling back to single chat');

            return $this->fallback($language, ['tools:plan prompt missing']);
        }

        $modelId = $this->modelConfigService->getDefaultModel('PLAN', $userId)
            ?? $this->modelConfigService->getDefaultModel('SORT', $userId);
        $provider = $modelId ? $this->modelConfigService->getProviderForModel($modelId) : null;
        $modelName = $modelId ? $this->modelConfigService->getModelName($modelId) : null;

        $systemPrompt = $this->buildSystemPrompt($promptRow->getPrompt(), $userId);
        $messages = $this->buildMessages($systemPrompt, $message, $conversationHistory);

        try {
            $response = $this->aiFacade->chat($messages, $userId, [
                'provider' => $provider,
                'model' => $modelName,
                'temperature' => 0.1,
                'max_tokens' => 1500,
            ]);
            $raw = (string) ($response['content'] ?? '');
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanner: planner model call failed, falling back', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallback($language, ['planner model call failed: '.$e->getMessage()], $modelId);
        }

        $decoded = $this->decodeJson($raw);
        if (null === $decoded) {
            return $this->fallback($language, ['planner output was not valid JSON'], $modelId, $raw);
        }

        $errors = $this->validator->validate($decoded);
        if ([] !== $errors) {
            $this->logger->info('TaskPlanner: plan failed validation, falling back', [
                'errors' => $errors,
            ]);

            return $this->fallback($language, $errors, $modelId, $raw);
        }

        try {
            /** @var array<string, mixed> $decoded */
            $plan = TaskPlan::fromArray($decoded, $this->validator);
        } catch (\Throwable $e) {
            return $this->fallback($language, ['plan build failed: '.$e->getMessage()], $modelId, $raw);
        }

        return new TaskPlanResult($plan, fallback: false, modelId: $modelId, rawResponse: $raw, errors: []);
    }

    /**
     * @param list<string> $errors
     */
    private function fallback(string $language, array $errors, ?int $modelId = null, string $raw = ''): TaskPlanResult
    {
        return new TaskPlanResult(
            TaskPlan::singleChatPlan($language),
            fallback: true,
            modelId: $modelId,
            rawResponse: $raw,
            errors: $errors,
        );
    }

    private function buildSystemPrompt(string $template, ?int $userId): string
    {
        $topics = $this->promptRepository->getAllTopics(0, $userId, excludeTools: true);
        $topicsWithDesc = $this->promptRepository->getTopicsWithDescriptions(0, '', $userId, excludeTools: true);

        $capabilityList = [];
        foreach (Capability::values() as $cap) {
            $capabilityList[] = '- "'.$cap.'": '.(self::CAPABILITY_DESCRIPTIONS[$cap] ?? '');
        }

        $dynamicList = [];
        foreach ($topicsWithDesc as $item) {
            $dynamicList[] = "- \"{$item['topic']}\": {$item['description']}";
        }

        $keyList = implode(' | ', array_map(static fn (string $t): string => '"'.$t.'"', $topics));

        $text = str_replace('[CAPABILITYLIST]', implode("\n", $capabilityList), $template);
        $text = str_replace('[DYNAMICLIST]', implode("\n", $dynamicList), $text);

        return str_replace('[KEYLIST]', $keyList, $text);
    }

    /**
     * @param array<int, Message> $conversationHistory
     *
     * @return list<array{role: string, content: string}>
     */
    private function buildMessages(string $systemPrompt, Message $message, array $conversationHistory): array
    {
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($conversationHistory as $msg) {
            if ('IN' === $msg->getDirection()) {
                $text = (string) $msg->getText();
                if ($msg->getFileText()) {
                    $text .= ' [file: '.$msg->getFileType().' — '.substr((string) $msg->getFileText(), 0, 200).']';
                }
                $messages[] = ['role' => 'user', 'content' => $text];
            } elseif ('OUT' === $msg->getDirection()) {
                $messages[] = ['role' => 'assistant', 'content' => substr((string) $msg->getText(), 0, 200)];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $this->buildCurrentMessageJson($message)];

        return $messages;
    }

    private function buildCurrentMessageJson(Message $message): string
    {
        $data = [
            'BTEXT' => $message->getText(),
            'BLANG' => $message->getLanguage() ?: 'en',
            'BFILETEXT' => $message->getFileText() ?: '',
        ];

        $attached = [];
        foreach ($message->getFiles() as $file) {
            $attached[] = $file->getFileType() ?: $file->getFileMime();
        }
        if ([] !== $attached) {
            $data['BATTACHED_FILES'] = implode(', ', $attached);
            $data['BATTACHED_COUNT'] = count($attached);
        } elseif ($message->getFile() > 0) {
            $data['BATTACHED_FILES'] = (string) $message->getFileType();
            $data['BATTACHED_COUNT'] = 1;
        }

        return json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Decode the model's JSON, tolerating markdown code fences and surrounding prose.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $text = trim($raw);
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = (string) preg_replace('/\s*```$/', '', $text);
            $text = trim($text);
        }

        // If the model wrapped the JSON in prose, grab the outermost object.
        if (!str_starts_with($text, '{')) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if (false !== $start && false !== $end && $end > $start) {
                $text = substr($text, $start, $end - $start + 1);
            }
        }

        try {
            $decoded = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
