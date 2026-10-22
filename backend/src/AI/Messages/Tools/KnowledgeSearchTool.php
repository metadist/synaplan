<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\AI\Messages\DesktopTurnOptions;
use App\Entity\ApiKey;
use App\Entity\User;
use App\Security\ApiKeyScope;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RAG\KnowledgeSearchHelper;
use App\Service\Runtime\RuntimeProfile;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gateway-owned search of the folder pinned on this message.
 *
 * Desktop does not answer it. The description tells the model to use it only
 * when the passages already injected are not enough.
 */
final readonly class KnowledgeSearchTool
{
    public const NAME = 'knowledge_search';

    public function __construct(
        private KnowledgeSearchHelper $knowledge,
        private RequestStack $requests,
    ) {
    }

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function declaration(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Search the pinned knowledge folder when the passages above are not enough. Returns the file name, file id, chunk id, score and text. Stay inside that folder.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'What to look for in the pinned folder.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many passages to return. At most 10. Defaults to 5.',
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }

    public function isOffered(string $mode, ?RuntimeProfile $assistant): bool
    {
        if (MessagesGatewayConfig::KNOWLEDGE_OFF === $mode) {
            return false;
        }
        if (MessagesGatewayConfig::KNOWLEDGE_DESKTOP === $mode && !$this->pairedDesktop()) {
            return false;
        }
        if (MessagesGatewayConfig::KNOWLEDGE_ALL !== $mode && MessagesGatewayConfig::KNOWLEDGE_DESKTOP !== $mode) {
            return false;
        }

        return $this->folderPinned($assistant);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{text: string, isError: bool, summary: string}
     */
    public function execute(array $input, User $user, ?RuntimeProfile $assistant): array
    {
        $query = \is_string($input['query'] ?? null) ? trim($input['query']) : '';
        if ('' === $query) {
            return $this->error('', 'knowledge_search requires a query.');
        }

        try {
            $found = $this->knowledge->searchForTool(
                (int) $user->getId(),
                $query,
                $this->ragGroupKey(),
                $assistant,
                $this->limit($input['limit'] ?? null),
            );
        } catch (\Throwable $e) {
            return $this->error($query, 'The folder could not be searched: '.$e->getMessage());
        }

        if (null !== $found['error']) {
            return $this->error($query, $found['error']);
        }
        if ([] === $found['hits']) {
            return [
                'text' => 'No passage in the pinned folder matched.',
                'isError' => false,
                'summary' => $query,
            ];
        }

        $blocks = [];
        foreach ($found['hits'] as $hit) {
            $blocks[] = sprintf(
                "file: %s\nfile_id: %s\nchunk_id: %s\nscore: %s\n\n%s",
                '' !== trim((string) ($hit['file_name'] ?? '')) ? trim((string) $hit['file_name']) : 'file',
                (string) ($hit['file_id'] ?? ''),
                (string) ($hit['chunk_id'] ?? ''),
                isset($hit['score']) && is_numeric($hit['score']) ? (string) round((float) $hit['score'], 4) : '',
                trim((string) ($hit['chunk_text'] ?? '')),
            );
        }

        return [
            'text' => implode("\n\n---\n\n", $blocks),
            'isError' => false,
            'summary' => $query,
        ];
    }

    private function pairedDesktop(): bool
    {
        $request = $this->requests->getCurrentRequest();
        $key = $request?->attributes->get('api_key');

        return $key instanceof ApiKey && ApiKeyScope::isPairedDesktop($key->getScopes());
    }

    private function folderPinned(?RuntimeProfile $assistant): bool
    {
        if (null !== $this->ragGroupKey()) {
            return true;
        }

        return null !== $assistant && [] !== $assistant->ragScopes;
    }

    private function ragGroupKey(): ?string
    {
        $request = $this->requests->getCurrentRequest();
        $rag = trim((string) $request?->headers->get(DesktopTurnOptions::HEADER_RAG_GROUP_KEY, ''));

        return '' !== $rag ? $rag : null;
    }

    private function limit(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return KnowledgeSearchHelper::DEFAULT_LIMIT;
        }

        return max(1, min(KnowledgeSearchHelper::MAX_LIMIT, (int) $raw));
    }

    /**
     * @return array{text: string, isError: bool, summary: string}
     */
    private function error(string $query, string $message): array
    {
        return ['text' => $message, 'isError' => true, 'summary' => $query];
    }
}
