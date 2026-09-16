<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\File\FileStorageService;

final readonly class FileSendService
{
    public function __construct(
        private FileRepository $files,
        private FileStorageService $storage,
        private DestinationRegistry $destinations,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function send(int $fileId, int $actorId, string $destinationId, array $params): array
    {
        $file = $this->files->find($fileId);
        if (null === $file) {
            return ['status' => 404, 'body' => ['error' => 'not_found', 'code' => DestinationFailureCode::NotFound->value]];
        }
        if ($file->getUserId() !== $actorId) {
            return ['status' => 403, 'body' => ['error' => 'unauthorized', 'code' => DestinationFailureCode::Unauthorized->value]];
        }

        try {
            $provider = $this->destinations->get($destinationId);
        } catch (UnknownDestinationException) {
            return ['status' => 400, 'body' => ['error' => 'unknown_destination']];
        }

        if (null === $file->getMessageId()) {
            $params['message_id'] = $params['message_id'] ?? null;
        } else {
            $params['message_id'] = $params['message_id'] ?? $file->getMessageId();
        }

        $result = $provider->send($this->toShareable($file), $params);
        if (!$result->ok) {
            $code = $result->code instanceof DestinationFailureCode
                ? $result->code->value
                : DestinationFailureCode::Unreachable->value;

            return [
                'status' => 422,
                'body' => [
                    'success' => false,
                    'code' => $code,
                    'context' => $result->context,
                ],
            ];
        }

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'destination' => $destinationId,
                'reference' => $result->reference,
                'context' => $result->context,
            ],
        ];
    }

    private function toShareable(File $file): ShareableFile
    {
        $id = $file->getId();
        if (null === $id) {
            throw new \RuntimeException('File is not persisted');
        }

        return new ShareableFile(
            fileId: $id,
            ownerId: $file->getUserId(),
            absolutePath: $this->storage->getAbsolutePath($file->getFilePath()),
            name: $file->getDisplayName(),
            sizeBytes: $file->getFileSize(),
        );
    }
}
