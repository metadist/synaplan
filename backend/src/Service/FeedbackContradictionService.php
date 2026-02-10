<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Repository\PromptRepository;
use Psr\Log\LoggerInterface;

/**
 * Checks for contradictions before saving feedback or memories.
 * Searches Qdrant across memories and feedback namespaces, asks AI if contradictions exist.
 */
final readonly class FeedbackContradictionService
{
    private const NAMESPACE_FALSE_POSITIVE = 'feedback_false_positive';
    private const NAMESPACE_POSITIVE = 'feedback_positive';
    private const MIN_SCORE = 0.4;
    private const LIMIT_PER_NAMESPACE = 5;

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private UserMemoryService $memoryService,
        private PromptRepository $promptRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Check if saving the given text as feedback would contradict existing memories or feedback.
     *
     * @param 'false_positive'|'positive' $type
     *
     * @return array{hasContradictions: bool, contradictions: array<int, array{id: int, type: string, value: string, reason: string}>}
     */
    public function checkContradictions(User $user, string $textToSave, string $type): array
    {
        if (!$this->memoryService->isAvailable()) {
            $this->logger->debug('FeedbackContradictionService: Memory service unavailable, skipping check');

            return ['hasContradictions' => false, 'contradictions' => []];
        }

        $textToSave = trim($textToSave);
        if (mb_strlen($textToSave) < 5) {
            return ['hasContradictions' => false, 'contradictions' => []];
        }

        $related = $this->fetchRelatedItems($user, $textToSave);
        if (empty($related)) {
            return ['hasContradictions' => false, 'contradictions' => []];
        }

        $contradictions = $this->askAiForContradictions($user, $textToSave, $type, $related);
        if (empty($contradictions)) {
            return ['hasContradictions' => false, 'contradictions' => []];
        }

        return [
            'hasContradictions' => true,
            'contradictions' => $contradictions,
        ];
    }

    /**
     * Check contradictions for BOTH summary and correction in a SINGLE operation.
     * Uses one combined vector search and one AI call instead of two separate checks.
     *
     * @return array{hasContradictions: bool, contradictions: array<int, array{id: int, type: string, value: string, reason: string}>}
     */
    public function checkContradictionsBatch(User $user, string $summary, string $correction): array
    {
        if (!$this->memoryService->isAvailable()) {
            return ['hasContradictions' => false, 'contradictions' => []];
        }

        $summary = trim($summary);
        $correction = trim($correction);

        if (mb_strlen($summary) < 5 && mb_strlen($correction) < 5) {
            return ['hasContradictions' => false, 'contradictions' => []];
        }

        // Combine both texts for a single vector search
        $searchText = trim("{$summary} {$correction}");
        $related = $this->fetchRelatedItems($user, $searchText);

        if (empty($related)) {
            return ['hasContradictions' => false, 'contradictions' => []];
        }

        // Ask AI about both statements at once
        $contradictions = $this->askAiForContradictionsBatch($user, $summary, $correction, $related);

        if (empty($contradictions)) {
            return ['hasContradictions' => false, 'contradictions' => []];
        }

        return [
            'hasContradictions' => true,
            'contradictions' => $contradictions,
        ];
    }

    /**
     * Fetch related memories and feedback via vector search across namespaces.
     *
     * @return array<int, array{id: int, type: string, value: string, score: float}>
     */
    private function fetchRelatedItems(User $user, string $queryText): array
    {
        $userId = $user->getId();
        $related = [];

        // User memories (namespace null, excludes hidden feedback categories)
        $memories = $this->memoryService->searchRelevantMemories(
            $userId,
            $queryText,
            null,
            self::LIMIT_PER_NAMESPACE,
            self::MIN_SCORE,
            null,
            false
        );
        foreach ($memories as $m) {
            $score = (float) ($m['score'] ?? 0);
            if ($score < self::MIN_SCORE) {
                continue;
            }
            $id = (int) ($m['id'] ?? 0);
            $key = (string) ($m['key'] ?? '');
            $val = (string) ($m['value'] ?? '');
            $value = ('' !== $key && '' !== $val) ? "{$key}: {$val}" : ($val ?: $key);
            if ($id > 0 && '' !== $value) {
                $related[] = [
                    'id' => $id,
                    'type' => 'memory',
                    'value' => $value,
                    'score' => $score,
                ];
            }
        }

        // False positives
        $falsePositives = $this->memoryService->searchRelevantMemories(
            $userId,
            $queryText,
            'feedback_negative',
            self::LIMIT_PER_NAMESPACE,
            self::MIN_SCORE,
            self::NAMESPACE_FALSE_POSITIVE,
            true
        );
        foreach ($falsePositives as $fp) {
            $score = (float) ($fp['score'] ?? 0);
            if ($score < self::MIN_SCORE) {
                continue;
            }
            $id = (int) ($fp['id'] ?? 0);
            $value = (string) ($fp['value'] ?? '');
            if ($id > 0 && '' !== $value) {
                $related[] = [
                    'id' => $id,
                    'type' => 'false_positive',
                    'value' => $value,
                    'score' => $score,
                ];
            }
        }

        // Positive examples
        $positives = $this->memoryService->searchRelevantMemories(
            $userId,
            $queryText,
            'feedback_positive',
            self::LIMIT_PER_NAMESPACE,
            self::MIN_SCORE,
            self::NAMESPACE_POSITIVE,
            true
        );
        foreach ($positives as $p) {
            $score = (float) ($p['score'] ?? 0);
            if ($score < self::MIN_SCORE) {
                continue;
            }
            $id = (int) ($p['id'] ?? 0);
            $value = (string) ($p['value'] ?? '');
            if ($id > 0 && '' !== $value) {
                $related[] = [
                    'id' => $id,
                    'type' => 'positive',
                    'value' => $value,
                    'score' => $score,
                ];
            }
        }

        return $related;
    }

    /**
     * Ask AI whether the new statement contradicts any of the related items.
     *
     * @param array<int, array{id: int, type: string, value: string, score: float}> $related
     *
     * @return array<int, array{id: int, type: string, value: string, reason: string}>
     */
    private function askAiForContradictions(User $user, string $newText, string $newType, array $related): array
    {
        $systemPrompt = $this->getContradictionPrompt();
        if ('' === $systemPrompt) {
            $this->logger->warning('FeedbackContradictionService: Contradiction prompt not found');

            return [];
        }

        $relatedBlock = $this->formatRelatedItems($related);

        $userPrompt = <<<PROMPT
NEW STATEMENT (user wants to save as "{$newType}"):
"{$newText}"

EXISTING RELATED ITEMS:
{$relatedBlock}

Analyze whether the new statement contradicts any of the existing items. A contradiction means:
- Same topic but opposite or conflicting information (e.g., "Sydney is capital" vs "Canberra is capital")
- Same fact with different values (e.g., "email is a@b.com" vs "email is c@d.com")

CRITICAL: Understand item types:
- "memory" and "positive" items = the user believes these are TRUE
- "false_positive" items = the user believes these are FALSE (the OPPOSITE is what the user considers true)
  Example: false_positive "Putin is Orthodox" means the user previously said Putin is NOT Orthodox.
  If the new statement says "Putin is Orthodox", that CONTRADICTS this false_positive.

Check for contradictions in BOTH directions: direct contradictions AND implied contradictions via type inversion.
Only report clear contradictions. Do NOT flag items that are merely related but not conflicting.
PROMPT;

        return $this->executeContradictionAiCall($user, $systemPrompt, $userPrompt, $related);
    }

    /**
     * Ask AI about contradictions for BOTH summary and correction in a single call.
     * Uses the dedicated TOOLS model to minimize cost.
     *
     * @param array<int, array{id: int, type: string, value: string, score: float}> $related
     *
     * @return array<int, array{id: int, type: string, value: string, reason: string}>
     */
    private function askAiForContradictionsBatch(User $user, string $summary, string $correction, array $related): array
    {
        $systemPrompt = $this->getContradictionPrompt();
        if ('' === $systemPrompt) {
            return [];
        }

        $relatedBlock = $this->formatRelatedItems($related);

        $parts = [];
        if ('' !== $summary) {
            $parts[] = "FALSE CLAIM (user marks as incorrect):\n\"{$summary}\"";
        }
        if ('' !== $correction) {
            $parts[] = "CORRECTION (user says this is correct):\n\"{$correction}\"";
        }
        $statementsBlock = implode("\n\n", $parts);

        $userPrompt = <<<PROMPT
NEW STATEMENTS the user wants to save:
{$statementsBlock}

EXISTING RELATED ITEMS:
{$relatedBlock}

Analyze whether ANY of the new statements contradict any existing items. A contradiction means:
- Same topic but opposite or conflicting information
- Same fact with different values

CRITICAL: Understand item types:
- "memory" and "positive" items = the user believes these are TRUE
- "false_positive" items = the user believes these are FALSE (the OPPOSITE is what the user considers true)
  Example: false_positive "Putin is Orthodox" means the user previously said Putin is NOT Orthodox.
  If the new correction says "Putin is Orthodox", that CONTRADICTS this false_positive.

Check for contradictions in BOTH directions: direct contradictions AND implied contradictions via type inversion.
Only report clear contradictions. Do NOT flag items that are merely related but not conflicting.
PROMPT;

        return $this->executeContradictionAiCall($user, $systemPrompt, $userPrompt, $related);
    }

    private function formatRelatedItems(array $related): string
    {
        $formatted = array_map(
            function ($r) {
                $type = $r['type'];
                $label = match ($type) {
                    'memory' => 'memory (stored as TRUE fact about the user)',
                    'positive' => 'positive (user CONFIRMED this is CORRECT)',
                    'false_positive' => 'false_positive (user marked this as INCORRECT — the OPPOSITE is what the user believes)',
                    default => $type,
                };

                return sprintf('- [%s] ID %d: "%s"', $label, $r['id'], $r['value']);
            },
            $related
        );

        return implode("\n", $formatted);
    }

    /**
     * @return array<int, array{id: int, type: string, value: string, reason: string}>
     */
    private function executeContradictionAiCall(User $user, string $systemPrompt, string $userPrompt, array $related): array
    {
        try {
            $toolsConfig = $this->modelConfigService->getToolsModelConfig();

            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                null,
                array_filter([
                    'provider' => $toolsConfig['provider'],
                    'model' => $toolsConfig['model'],
                    'temperature' => 0.2,
                ])
            );

            $content = trim((string) ($response['content'] ?? ''));

            return $this->parseContradictionResponse($content, $related);
        } catch (\Throwable $e) {
            $this->logger->warning('FeedbackContradictionService: AI contradiction check failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Parse AI JSON response into contradiction list.
     *
     * @param array<int, array{id: int, type: string, value: string, score: float}> $related
     *
     * @return array<int, array{id: int, type: string, value: string, reason: string}>
     */
    private function parseContradictionResponse(string $content, array $related): array
    {
        $idMap = [];
        foreach ($related as $r) {
            $key = $r['type'].'_'.$r['id'];
            $idMap[$key] = $r;
        }

        $json = $this->extractJson($content);
        if (null === $json) {
            return [];
        }

        $contradictions = [];
        $items = $json['contradictions'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            $type = (string) ($item['type'] ?? '');
            $value = (string) ($item['value'] ?? '');
            $reason = (string) ($item['reason'] ?? 'Contradicts new statement');

            if ($id <= 0 || !in_array($type, ['memory', 'false_positive', 'positive'], true)) {
                continue;
            }
            $key = $type.'_'.$id;
            if (!isset($idMap[$key])) {
                continue;
            }
            $contradictions[] = [
                'id' => $id,
                'type' => $type,
                'value' => $value ?: $idMap[$key]['value'],
                'reason' => $reason,
            ];
        }

        return $contradictions;
    }

    /**
     * Extract the first JSON object from a string (handles markdown code fences).
     */
    private function extractJson(string $content): ?array
    {
        // Strip markdown fences if present
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content) ?? $content;
        $content = preg_replace('/^```\s*$/m', '', $content) ?? $content;

        if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function getContradictionPrompt(): string
    {
        $prompt = $this->promptRepository->findOneBy([
            'topic' => 'tools:feedback_contradiction_check',
            'language' => 'en',
            'ownerId' => 0,
        ]);

        if ($prompt) {
            return $prompt->getPrompt();
        }

        return <<<'FALLBACK'
You analyze whether a new statement contradicts existing stored items. Respond ONLY with valid JSON in this exact format:
{"contradictions":[{"id":123,"type":"memory","value":"old text","reason":"brief reason"}]}

Rules:
- Only include items that CLEARLY contradict the new statement (same fact, different/opposite value).
- type must be one of: memory, false_positive, positive
- id and value must match the provided existing items
- CRITICAL: "false_positive" items were marked as INCORRECT by the user — the user believes the OPPOSITE.
  So false_positive "X is true" means the user thinks "X is NOT true".
  If a new statement agrees with "X is true", it contradicts this false_positive.
- If no contradictions, return: {"contradictions":[]}
- Output ONLY the JSON, no other text.
FALLBACK;
    }
}
