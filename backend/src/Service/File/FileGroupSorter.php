<?php

declare(strict_types=1);

namespace App\Service\File;

use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * Picks the best knowledge group (folder) for a file from its extracted text /
 * description and the user's existing groups — the "sort" half of the file
 * manager's "Describe, vectorize & sort" action.
 *
 * It reuses the user's configured SORT model (the same one that classifies
 * messages). The model returns a single short group name: an existing group
 * when one fits, otherwise a concise new label. The result is sanitised so a
 * chatty model can never produce a multi-line or oversized group key.
 */
final readonly class FileGroupSorter
{
    /**
     * Hard ceiling for a group key — BFILES.BGROUPKEY is varchar(128) but a
     * usable folder name is far shorter; anything longer signals the model
     * ignored the instruction, so we discard it.
     */
    private const MAX_GROUP_LENGTH = 64;

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Suggest a knowledge group for the given text.
     *
     * @param string        $text           extracted text / description of the file
     * @param array<string> $existingGroups the user's current group names
     *
     * @return string|null a group name (existing or new), or null when no
     *                     sensible group could be derived (caller keeps the
     *                     file ungrouped)
     */
    public function suggestGroup(string $text, array $existingGroups, int $userId): ?string
    {
        $text = trim($text);
        if ('' === $text) {
            return null;
        }

        $modelId = $this->modelConfigService->getDefaultModel('SORT', $userId);
        if (!$modelId) {
            $this->logger->warning('FileGroupSorter: No SORT model configured, leaving file ungrouped');

            return null;
        }

        $provider = $this->modelConfigService->getProviderForModel($modelId);
        $modelName = $this->modelConfigService->getModelName($modelId);
        if (!$provider || !$modelName) {
            $this->logger->warning('FileGroupSorter: SORT model misconfigured, leaving file ungrouped');

            return null;
        }

        $groups = array_values(array_filter(array_map(
            static fn (string $g): string => trim($g),
            $existingGroups,
        ), static fn (string $g): bool => '' !== $g && 'DEFAULT' !== $g));

        $groupList = empty($groups) ? '(none yet)' : implode(', ', $groups);

        $system = 'You organize a user\'s files into knowledge groups (folders). '
            .'Given a file description and the list of existing groups, pick the single '
            .'best existing group for this file. If none fit well, propose a short new '
            .'group name of 1-3 words in Title Case. '
            .'Reply with ONLY the group name — no quotes, no punctuation, no explanation.';

        $user = "Existing groups: {$groupList}\n\nFile description:\n".mb_substr($text, 0, 4000);

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                $userId,
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.2,
                    'max_tokens' => 20,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('FileGroupSorter: SORT model call failed, leaving file ungrouped', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->sanitize((string) ($response['content'] ?? ''), $groups);
    }

    /**
     * Reduce a raw model reply to a single, safe group key. Returns null when
     * the reply is empty, too long, or multi-line (a sign the model ignored the
     * "name only" instruction). Matches an existing group case-insensitively so
     * we don't create a near-duplicate folder.
     *
     * @param array<string> $existingGroups
     */
    private function sanitize(string $raw, array $existingGroups): ?string
    {
        $name = trim($raw);
        // Collapse to the first line and strip wrapping quotes / trailing punctuation.
        $name = trim((string) preg_split('/\r?\n/', $name)[0]);
        $name = trim($name, " \t\"'`.,:;");

        if ('' === $name || mb_strlen($name) > self::MAX_GROUP_LENGTH) {
            return null;
        }

        foreach ($existingGroups as $existing) {
            if (0 === strcasecmp($existing, $name)) {
                return $existing;
            }
        }

        return $name;
    }
}
