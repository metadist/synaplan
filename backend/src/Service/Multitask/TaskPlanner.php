<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\Plan\TaskPlanValidator;
use App\Service\Prompt\TimeContextBuilder;
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
        'file_analysis' => 'Analyze/describe/OCR an image or document — either attached by the user or produced by a prior node ($nX.file) — and answer about it.',
        'image_generation' => 'Generate or edit an image from a prompt and/or reference images.',
        'video_generation' => 'Generate a video clip (params.duration, params.resolution).',
        'text2sound' => 'Synthesize speech/audio from text (params.format, e.g. mp3).',
        'document_generation' => 'Generate an Office document (CSV/XLSX/DOCX/PPTX).',
        'calendar_event' => 'Create a calendar meeting/invite as a downloadable .ics file. params: title, start (ISO-8601 local datetime, e.g. "2026-06-09T15:00:00"), end (ISO-8601) or duration_minutes, timezone (IANA, e.g. "Europe/Berlin"), location, description, attendees (list of names/emails). Resolve relative times against the current time context below.',
        'email_me' => 'Email the results to the account owner as one multi-part mail (text + attachments from other nodes). ONLY when the user explicitly asks to be mailed/emailed the result ("mail it to me", "send it to my email"). Inputs: text, attachments. Never the reply node.',
        'compose_reply' => 'Assemble final reply: text + N file attachments from other nodes.',
    ];

    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private TaskPlanValidator $validator,
        private LoggerInterface $logger,
        private UserRepository $userRepository,
        private TimeContextBuilder $timeContextBuilder,
    ) {
    }

    /**
     * Produce a task plan for the given message. Never throws — always returns a
     * usable plan (real or single-`chat` fallback).
     *
     * @param array<int, Message>  $conversationHistory oldest-first thread
     * @param array<string, mixed> $options             request context (e.g. client_country) used to resolve the user's local time
     */
    public function plan(Message $message, array $conversationHistory = [], ?int $userId = null, array $options = []): TaskPlanResult
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

        $systemPrompt = $this->buildSystemPrompt($promptRow->getPrompt(), $userId, $message, $options);
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

    /**
     * Render a planner prompt template the same way the live planner does
     * ([CAPABILITYLIST]/[DYNAMICLIST]/[KEYLIST] substitution). Exposed so the
     * admin config UI can show an accurate preview of what the model receives.
     */
    public function renderSystemPrompt(string $template, ?int $userId): string
    {
        return $this->buildSystemPrompt($template, $userId);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildSystemPrompt(string $template, ?int $userId, ?Message $message = null, array $options = []): string
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
        $text = str_replace('[KEYLIST]', $keyList, $text);

        return $text."\n\n".$this->timeContextBlock($message, $options);
    }

    /**
     * A short block giving the planner the current time in the USER's local
     * timezone (not the server's) and, when available, when this message was
     * received. The planner uses it to resolve relative dates/times ("tomorrow
     * at 15:00") into the absolute `start`/`timezone` a `calendar_event` node
     * needs. Appended unconditionally so it works regardless of the stored
     * prompt template version.
     *
     * Timezone resolution is delegated to {@see TimeContextBuilder} — the SAME
     * profile→country→server fallback the chat handler uses — so a UTC server
     * no longer makes the planner emit UTC wall-clock times for a user in, say,
     * Europe/Berlin (which previously surfaced as a 2-hour-off meeting invite).
     *
     * @param array<string, mixed> $options
     */
    private function timeContextBlock(?Message $message, array $options = []): string
    {
        [$userTimezone, $country] = $this->resolveTimeSignals($message, $options);

        $lines = [trim($this->timeContextBuilder->build($userTimezone, $country))];

        if (null !== $message) {
            $received = $message->getDateTime();
            if ('' !== $received) {
                $lines[] = '- This message was received at (raw server stamp): '.$received.'.';
            }
        }

        $lines[] = 'For a calendar/meeting request, emit a "calendar_event" node with an absolute "start" as an ISO-8601 LOCAL datetime in the user\'s local timezone shown above, plus the matching IANA "timezone" string. Do NOT convert the time to UTC and do NOT emit "UTC" unless that is genuinely the user\'s timezone.';

        return implode("\n", $lines);
    }

    /**
     * Resolve the two timezone signals the {@see TimeContextBuilder} needs: the
     * user's stored IANA timezone (profile, authoritative) and the approximate
     * Cloudflare country. Mirrors {@see \App\Service\Message\Handler\ChatHandler::buildTimeContext()}.
     *
     * @param array<string, mixed> $options
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveTimeSignals(?Message $message, array $options): array
    {
        $userTimezone = null;
        $userId = $message?->getUserId();
        if (null !== $userId && $userId > 0) {
            $details = $this->userRepository->find($userId)?->getUserDetails() ?? [];
            $tz = $details['timezone'] ?? null;
            $userTimezone = is_string($tz) && '' !== trim($tz) ? trim($tz) : null;
        }

        $country = $options['client_country'] ?? null;

        return [$userTimezone, is_string($country) ? $country : null];
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
