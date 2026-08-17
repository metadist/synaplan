<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\Destination\RequestedFolderDelivery;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use Psr\Log\LoggerInterface;

/**
 * `save_to_folder` runner — writes upstream files into a connected WebDAV /
 * Nextcloud folder. No model call; side-channel delivery like {@see EmailMeRunner}.
 *
 * Inputs: `attachments` (list of `$nX.file`). Params: `channel` from
 * `[CHANNELLIST]` (numeric `connection_id` is accepted as a fallback).
 * `compose_reply` must not depend on this node.
 */
final readonly class SaveToFolderRunner implements TaskRunner
{
    public function __construct(
        private RequestedFolderDelivery $delivery,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::SaveToFolder];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::SaveToFolder,
                'Save generated files into a connected folder channel from the Connected channels list (kind folder). ONLY when the user explicitly asks to put/save/file the result there ("save it to my Nextcloud", "lege es in nextcloud"). Inputs: attachments. params.channel MUST be a folder name from that list (e.g. "nextcloud"). Never invent a name. Never the reply node.',
                dynamicNote: fn (?int $userId, array $context): ?string => $this->renderAvailabilityNote($userId),
                requiresDynamicNote: true,
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $userId = $context->userId ?? (int) $context->message->getUserId();
        $inputs = $context->resolveInputs($node);
        $files = $this->resolveFiles($inputs['attachments'] ?? []);
        $channel = is_string($node->params['channel'] ?? null)
            ? trim($node->params['channel'])
            : (is_numeric($node->params['connection_id'] ?? null) ? (int) $node->params['connection_id'] : null);

        $result = $this->delivery->send($userId, $files, '' === $channel ? null : $channel);
        if (!$result['ok']) {
            $this->logger->warning('SaveToFolderRunner: delivery failed', [
                'user_id' => $userId,
                'error' => $result['message'],
            ]);

            return NodeResult::failed($result['message']);
        }

        $context->streamChunk($result['message']);

        return NodeResult::ok($result['message'], [], [
            'folder_connection' => $result['connection'],
            'folder_channel' => $result['channel'],
            'files_saved' => $result['sent'],
        ]);
    }

    /**
     * The try/catch mirrors EmailSearchRunner: SkillCatalogFactory builds
     * runners without constructors for DB-free tests, so touching an injected
     * dependency here must degrade to "no note", never fatal.
     */
    private function renderAvailabilityNote(?int $userId): ?string
    {
        if (null === $userId || $userId <= 0) {
            return null;
        }

        try {
            if (!$this->delivery->hasFolderChannel($userId)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return '  Use a folder channel from the Connected channels list as params.channel.';
    }

    /**
     * @return list<array{path: string, name: string}>
     */
    private function resolveFiles(mixed $value): array
    {
        $out = [];
        foreach ($this->flatten($value) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $absolute = $this->resolveAbsolutePath($candidate);
            if (null === $absolute) {
                continue;
            }
            $name = is_string($candidate['name'] ?? null) && '' !== $candidate['name']
                ? $candidate['name']
                : basename($absolute);
            $out[] = ['path' => $absolute, 'name' => $name];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private function resolveAbsolutePath(array $descriptor): ?string
    {
        $relative = is_string($descriptor['local_path'] ?? null) ? $descriptor['local_path'] : null;

        if (null === $relative || '' === trim($relative)) {
            $servePrefix = '/api/v1/files/uploads/';
            $url = is_string($descriptor['path'] ?? null) ? $descriptor['path'] : null;
            if (null !== $url && str_starts_with($url, $servePrefix)) {
                $relative = substr($url, strlen($servePrefix));
            }
        }

        if (null === $relative || '' === trim($relative)) {
            return null;
        }

        $baseDir = realpath(rtrim($this->uploadDir, '/')) ?: rtrim($this->uploadDir, '/');
        $resolved = realpath($baseDir.'/'.ltrim($relative, '/'));
        $isWithinBaseDir = false !== $resolved
            && (str_starts_with($resolved, $baseDir.DIRECTORY_SEPARATOR) || $resolved === $baseDir);

        if (false === $resolved || !$isWithinBaseDir || !is_file($resolved)) {
            $this->logger->warning('SaveToFolderRunner: file not found or outside uploads dir', [
                'descriptor_path' => $descriptor['path'] ?? null,
                'descriptor_local_path' => $descriptor['local_path'] ?? null,
            ]);

            return null;
        }

        return $resolved;
    }

    /**
     * @return list<mixed>
     */
    private function flatten(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item) && !isset($item['path'])) {
                foreach ($item as $sub) {
                    $out[] = $sub;
                }
            } else {
                $out[] = $item;
            }
        }

        return $out;
    }
}
