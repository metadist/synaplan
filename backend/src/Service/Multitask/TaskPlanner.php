<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\Plan\TaskPlanValidator;
use App\Service\Multitask\Skill\SkillCatalog;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\PromptService;
use App\Service\RateLimitService;
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
    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private TaskPlanValidator $validator,
        private LoggerInterface $logger,
        private UserRepository $userRepository,
        private TimeContextBuilder $timeContextBuilder,
        private SkillCatalog $skillCatalog,
        private PromptService $promptService,
        private RateLimitService $rateLimitService,
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

            // The planner call is a billable LLM request like the sorter's —
            // record it so DAG turns don't get their routing tokens for free.
            // Never let a recording hiccup break planning.
            $this->recordPlanningUsage($userId, $modelId, $response);
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
     * Record token usage of the planner call (BACTION=PLANNING). Mirrors
     * MessageSorter::recordSortingUsage — never throws, best-effort.
     *
     * @param array<string, mixed> $response
     */
    private function recordPlanningUsage(?int $userId, ?int $modelId, array $response): void
    {
        if (null === $userId || $userId <= 0) {
            return;
        }

        try {
            $user = $this->userRepository->find($userId);
            if (null === $user) {
                return;
            }

            $this->rateLimitService->recordUsage($user, 'PLANNING', [
                'usage' => $response['usage'] ?? [],
                'model_id' => $modelId,
                'provider' => $response['provider'] ?? '',
                'model' => $response['model'] ?? '',
                'input_text' => '',
                'response_text' => $response['content'] ?? '',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanner: failed to record planning usage', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
        }
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

        // Catalog-lite (release 4.0): the capability list is assembled from the
        // SkillDescriptors the runners declare — one source of truth per block.
        // Dynamic blocks (mcp_fetch) additionally receive the resolved routing
        // topic + its metadata so per-prompt gates (`tool_mcp`) decide whether
        // their sub-catalog is injected at all (plan 09 §3.2).
        $capabilityList = $this->skillCatalog->renderCapabilityList($userId, $this->catalogContext($userId, $options));

        $dynamicList = [];
        foreach ($topicsWithDesc as $item) {
            $dynamicList[] = "- \"{$item['topic']}\": {$item['description']}";
        }

        $keyList = implode(' | ', array_map(static fn (string $t): string => '"'.$t.'"', $topics));

        $text = str_replace('[CAPABILITYLIST]', $capabilityList, $template);
        $text = str_replace('[DYNAMICLIST]', implode("\n", $dynamicList), $text);
        $text = str_replace('[KEYLIST]', $keyList, $text);

        return $text."\n\n".$this->timeContextBlock($message, $options);
    }

    /**
     * Render context for dynamic skill blocks: the turn's resolved routing
     * topic (from the classification the executor forwards in the options)
     * and that topic's BPROMPTMETA map. Failures degrade to an empty context
     * — a metadata hiccup must never break planning.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function catalogContext(?int $userId, array $options): array
    {
        $classification = is_array($options['classification'] ?? null) ? $options['classification'] : [];
        $topic = is_string($classification['topic'] ?? null) ? $classification['topic'] : '';
        if ('' === $topic) {
            return [];
        }

        $topicMetadata = [];
        try {
            $promptData = $this->promptService->getPromptWithMetadata($topic, $userId ?? 0);
            if (null !== $promptData && is_array($promptData['metadata'] ?? null)) {
                $topicMetadata = $promptData['metadata'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanner: failed to resolve topic metadata for the skill catalog (ignored)', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }

        return ['topic' => $topic, 'topic_metadata' => $topicMetadata];
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
