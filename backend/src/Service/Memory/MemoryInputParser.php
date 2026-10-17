<?php

declare(strict_types=1);

namespace App\Service\Memory;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\JsonResponseDecoder;
use App\AI\StructuredOutput\Schema\UserMemoryActionSchema;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use App\Service\RateLimitService;

/**
 * Turns what a user typed into proposed memory actions (create, update,
 * delete): the memory model weighs the input against similar memories under
 * the tools:memory_parse prompt, and its answer is read in any of three
 * shapes. An update or delete may only name a memory of the user; an update
 * of an unknown memory becomes a create.
 */
final readonly class MemoryInputParser
{
    public function __construct(
        private UserMemoryService $memoryService,
        private AiFacade $aiFacade,
        private PromptService $promptService,
        private ModelConfigService $modelConfigService,
        private RateLimitService $rateLimitService,
        private StructuredOutputConfig $structuredOutputConfig,
        private JsonResponseDecoder $jsonDecoder = new JsonResponseDecoder(),
    ) {
    }

    public function parse(User $user, string $input, mixed $suggestedCategory): MemoryParseResult
    {
        // Resolve memory model up front so we can fail fast with a clear, actionable
        // error code when no model is configured. Without this pre-check the request
        // would either reach the provider with an empty model (provider-dependent
        // behaviour) or fail later inside the AI stack with a confusing message.
        //
        // IMPORTANT: this MUST use the same MEM → CHAT fallback chain as the async
        // MemoryExtractionService — otherwise the "New Memory" UI silently runs on
        // the expensive CHAT model (e.g. Claude Opus) instead of the dedicated MEM
        // model (e.g. Groq gpt-oss-120b). See issue #973.
        $memoryModelConfig = $this->modelConfigService->getMemoryModelConfig($user->getId());
        if (null === $memoryModelConfig['model'] || null === $memoryModelConfig['provider']) {
            return MemoryParseResult::noModel();
        }

        // Search for similar memories using Qdrant
        $similarMemories = [];
        $searchError = null;
        try {
            $similarMemories = $this->memoryService->searchMemories($user, $input, null, 10);
        } catch (\Exception $e) {
            $searchError = $e->getMessage();
            // Continue without context but log for debugging
        }

        $systemPrompt = $this->systemPrompt($user);
        $userMessage = $this->userMessage($input, $suggestedCategory, $similarMemories);

        try {
            $aiOptions = array_filter([
                'model' => $memoryModelConfig['model'],
                'provider' => $memoryModelConfig['provider'],
                'temperature' => 0.3, // Low temperature for consistent JSON output
            ]);

            if ($this->structuredOutputConfig->isEnabled($user->getId())) {
                $aiOptions['structured_output'] = UserMemoryActionSchema::build();
            }

            $response = $this->aiFacade->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                userId: $user->getId(),
                options: $aiOptions
            );

            $content = $response['content'] ?? '';

            $this->rateLimitService->recordUsage($user, 'MEMORY_PARSE', [
                'provider' => $response['provider'] ?? 'unknown',
                'model' => $response['model'] ?? 'unknown',
                'model_id' => $memoryModelConfig['model_id'] ?? null,
                'usage' => $response['usage'] ?? [],
                'response_text' => $content,
                'input_text' => $userMessage,
            ]);

            // Get ALL valid memory IDs for this user to validate AI suggestions
            // We need all user memories, not just similar ones, because AI might reference any
            $allUserMemories = $this->memoryService->getUserMemories($user->getId());
            $validMemoryIds = array_map(fn ($m) => $m->id, $allUserMemories);

            $actions = $this->parseAiResponse($content, $input, $validMemoryIds);
        } catch (ProviderException $e) {
            return MemoryParseResult::providerUnavailable($e->getMessage());
        }

        return [] === $actions
            ? MemoryParseResult::notUnderstood($content)
            : MemoryParseResult::parsed($actions, $similarMemories, $searchError, $content);
    }

    /**
     * The tools:memory_parse prompt, or a built-in one so the feature works
     * even without fixtures.
     */
    private function systemPrompt(User $user): string
    {
        $promptData = $this->promptService->getPromptWithMetadata('tools:memory_parse', $user->getId());
        $promptEntity = $promptData['prompt'] ?? null;

        if (!$promptEntity) {
            // Fallback: use a hardcoded prompt so the feature works even without fixtures
            $systemPrompt = 'Parse the user input into a structured memory. Return valid JSON with: action (create/update/delete), memory (object with category, key, value), and optionally existingId and reason. Categories: personal, work, preferences, health, finance, education, relationships, goals, other.';
        } else {
            $systemPrompt = $promptEntity->getPrompt();
        }

        return $systemPrompt;
    }

    /**
     * The input with the suggested category and the similar memories the
     * model may update or delete.
     *
     * @param array<mixed> $similarMemories
     */
    private function userMessage(string $input, mixed $suggestedCategory, array $similarMemories): string
    {
        $userMessage = "User input: \"{$input}\"";

        if ($suggestedCategory) {
            $userMessage .= "\nSuggested category: {$suggestedCategory}";
        }

        if (!empty($similarMemories)) {
            $userMessage .= "\n\nExisting memories that might be related:\n";
            foreach ($similarMemories as $mem) {
                $userMessage .= sprintf(
                    "- ID: %d, Key: %s, Value: %s\n",
                    $mem['id'] ?? 0,
                    $mem['key'] ?? '',
                    $mem['value'] ?? ''
                );
            }
        } else {
            $userMessage .= "\n\nNo existing memories found.";
        }

        return $userMessage;
    }

    /**
     * Parse AI response supporting multiple formats.
     *
     * Supports:
     * 1. Standard format: {"actions": [...]}
     * 2. Legacy single action: {"action": "create", "memory": {...}}
     * 3. NDJSON format: Multiple JSON objects on separate lines
     *
     * @param string $content        Raw AI response content
     * @param string $input          Original user input
     * @param int[]  $validMemoryIds Valid memory IDs for validation
     *
     * @return array Parsed actions
     */
    private function parseAiResponse(string $content, string $input, array $validMemoryIds): array
    {
        // Formats 1 and 2 arrive as ONE JSON document, possibly fenced,
        // prose-wrapped or truncated — all of which the shared decoder
        // handles.
        $decoded = $this->jsonDecoder->decode($content);
        if ($decoded->success) {
            $actions = $this->actionsFromDocument($decoded->data, $input, $validMemoryIds);
            if (null !== $actions) {
                return $actions;
            }
        }

        // Format 3: NDJSON — one action object per line, which is not a single
        // JSON document, so the decoder runs per line instead. Fence lines and
        // prose lines simply fail to decode and are skipped.
        $actions = [];
        foreach (preg_split('/\r?\n/', trim($content)) ?: [] as $line) {
            $lineResult = $this->jsonDecoder->decode($line);
            if (!$lineResult->success || !isset($lineResult->data['action'])) {
                continue;
            }

            $action = $this->parseActionData($lineResult->data, $input, $validMemoryIds);
            if ($action) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * Read the two single-document shapes out of a decoded response.
     *
     * @param array<string, mixed> $parsed
     * @param int[]                $validMemoryIds
     *
     * @return array<int, array<string, mixed>>|null null when the document is
     *                                               neither shape, so the caller can fall through to NDJSON
     */
    private function actionsFromDocument(array $parsed, string $input, array $validMemoryIds): ?array
    {
        $actions = [];

        // Format 1: {"actions": [...]} — also what UserMemoryActionSchema
        // constrains the schema path to.
        if (isset($parsed['actions']) && is_array($parsed['actions'])) {
            foreach ($parsed['actions'] as $actionData) {
                if (!is_array($actionData)) {
                    continue;
                }

                $action = $this->parseActionData($actionData, $input, $validMemoryIds);
                if ($action) {
                    $actions[] = $action;
                }
            }

            return $actions;
        }

        // Format 2: single action {"action": "create", "memory": {...}}
        if (isset($parsed['action'])) {
            $action = $this->parseActionData($parsed, $input, $validMemoryIds);
            if ($action) {
                $actions[] = $action;
            }

            return $actions;
        }

        return null;
    }

    /**
     * Parse a single action from AI response.
     *
     * @param array  $actionData     The action data from AI
     * @param string $input          Original user input
     * @param int[]  $validMemoryIds Valid memory IDs from similar memories search
     *
     * @return array|null Parsed action or null if invalid
     */
    private function parseActionData(array $actionData, string $input, array $validMemoryIds = []): ?array
    {
        $action = $actionData['action'] ?? null;

        if (!in_array($action, ['create', 'update', 'delete'], true)) {
            return null;
        }

        // Validate existingId if provided - it must be a valid memory ID
        $existingId = null;
        if (isset($actionData['existingId'])) {
            $proposedId = (int) $actionData['existingId'];
            // Only accept the ID if it's in our list of valid memories
            if (in_array($proposedId, $validMemoryIds, true)) {
                $existingId = $proposedId;
            }
        }

        // For update/delete, we need a valid existingId
        // If AI suggested update/delete but the ID is invalid, convert to create
        if (in_array($action, ['update', 'delete'], true) && null === $existingId) {
            if ('delete' === $action) {
                // Can't delete without a valid ID, skip this action
                return null;
            }
            // Convert invalid update to create
            $action = 'create';
        }

        $result = ['action' => $action];

        // For create/update, memory field is required
        if (in_array($action, ['create', 'update'], true)) {
            if (!isset($actionData['memory'])) {
                return null;
            }

            $result['memory'] = [
                'category' => (string) ($actionData['memory']['category'] ?? 'preferences'),
                'key' => (string) ($actionData['memory']['key'] ?? 'note'),
                'value' => (string) ($actionData['memory']['value'] ?? trim($input)),
            ];
        }

        if (null !== $existingId) {
            $result['existingId'] = $existingId;
        }

        if (isset($actionData['reason'])) {
            $result['reason'] = (string) $actionData['reason'];
        }

        return $result;
    }
}
