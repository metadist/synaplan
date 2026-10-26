<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\AI\Messages\DesktopTurnOptions;
use App\Entity\User;
use App\Service\RAG\KnowledgeSearchHelper;
use App\Service\Runtime\RuntimeProfile;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gateway-owned read of one file in the folder pinned on this message.
 *
 * `around` is a chunk id from {@see KnowledgeSearchTool}. Omitting it returns
 * the start of the file. A short file comes back whole.
 */
final readonly class ReadDocumentTool
{
    public const NAME = 'read_document';
    public const READ_FAILED = 'The file could not be read right now. Answer from the passages you already have.';

    public function __construct(
        private KnowledgeSearchHelper $knowledge,
        private RequestStack $requests,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function declaration(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Read one file in the pinned knowledge folder when the passages above are not enough. Pass file_id from knowledge_search. Pass around as that chunk_id to include the neighbouring text, or omit it to read the start of the file.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'file_id' => [
                        'type' => 'integer',
                        'description' => 'file_id from a knowledge_search result.',
                    ],
                    'around' => [
                        'type' => 'string',
                        'description' => 'chunk_id to read around. Omit to read the start of the file.',
                    ],
                ],
                'required' => ['file_id'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{text: string, isError: bool, summary: string}
     */
    public function execute(array $input, User $user, ?RuntimeProfile $assistant): array
    {
        $fileId = $input['file_id'] ?? null;
        if (!is_numeric($fileId) || (int) $fileId < 1) {
            return $this->error('', 'read_document requires a file_id.');
        }

        $rawAround = $input['around'] ?? null;
        $around = \is_string($rawAround) || \is_int($rawAround) ? trim((string) $rawAround) : '';

        try {
            $read = $this->knowledge->readForTool(
                (int) $user->getId(),
                (int) $fileId,
                '' !== $around ? $around : null,
                $this->ragGroupKey(),
                $assistant,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('ReadDocumentTool: read failed', [
                'user_id' => $user->getId(),
                'file_id' => (int) $fileId,
                'error' => $e->getMessage(),
            ]);

            return $this->error((string) (int) $fileId, self::READ_FAILED);
        }

        return [
            'text' => $read['text'],
            'isError' => $read['isError'],
            'summary' => (string) (int) $fileId,
        ];
    }

    private function ragGroupKey(): ?string
    {
        $request = $this->requests->getCurrentRequest();
        $rag = trim((string) $request?->headers->get(DesktopTurnOptions::HEADER_RAG_GROUP_KEY, ''));

        return '' !== $rag ? $rag : null;
    }

    /**
     * @return array{text: string, isError: bool, summary: string}
     */
    private function error(string $summary, string $message): array
    {
        return ['text' => $message, 'isError' => true, 'summary' => $summary];
    }
}
