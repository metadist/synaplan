<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Entity\User;
use App\Repository\ConnectionRepository;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeRefusedException;
use App\Service\Compute\ComputeWorkspaceService;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

/**
 * Copy one file-work folder file into the owner's Nextcloud or OpenCloud.
 */
final readonly class WorkspaceFolderPushService
{
    private const UNAVAILABLE = 'File work is not reachable right now. Nothing was copied.';

    public function __construct(
        private ComputeConfig $config,
        private ComputeWorkspaceService $workspaces,
        private ConnectionRepository $connections,
        private DestinationRegistry $destinations,
    ) {
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function push(User $user, string $path, int $connectionId): array
    {
        $userId = (int) $user->getId();
        if (!$this->config->workspacesEnabled($userId)) {
            return [
                'status' => 404,
                'body' => ['success' => false, 'error' => 'feature_not_configured', 'code' => 'feature_not_configured'],
            ];
        }

        $connection = $this->connections->findByIdAndOwner($connectionId, $userId);
        $kind = null !== $connection ? CloudFolderTarget::kindFor($connection) : null;
        if (null === $connection || null === $kind) {
            return [
                'status' => 422,
                'body' => [
                    'success' => false,
                    'code' => DestinationFailureCode::Unauthorized->value,
                    'context' => ['connection' => 'folder'],
                ],
            ];
        }

        try {
            $downloaded = $this->workspaces->downloadFile($user, $path);
        } catch (ComputeRefusedException $e) {
            return self::refusedDownload($e, $path, $connection->getName());
        } catch (HttpClientException) {
            return [
                'status' => 503,
                'body' => [
                    'success' => false,
                    'code' => 'compute_unavailable',
                    'message' => self::UNAVAILABLE,
                    'context' => ['connection' => $connection->getName()],
                ],
            ];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'synaplan-push-');
        if (false === $tmp || false === file_put_contents($tmp, $downloaded['contents'])) {
            return [
                'status' => 500,
                'body' => [
                    'success' => false,
                    'code' => DestinationFailureCode::Unreachable->value,
                    'context' => ['connection' => $connection->getName()],
                ],
            ];
        }

        try {
            $result = $this->destinations->get('webdav')->send(
                new ShareableFile(
                    fileId: 0,
                    ownerId: $userId,
                    absolutePath: $tmp,
                    name: $downloaded['name'],
                    sizeBytes: strlen($downloaded['contents']),
                ),
                ['connection_id' => $connectionId],
            );
        } finally {
            @unlink($tmp);
        }

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
                'destination' => 'webdav',
                'kind' => $kind,
                'reference' => $result->reference,
                'context' => $result->context,
            ],
        ];
    }

    /**
     * Only a missing workspace or a rejected path is "not found". Other sidecar
     * refusals (busy, quota, internal_error) must keep a matching failure code
     * so the UI does not say the file is gone.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function refusedDownload(
        ComputeRefusedException $e,
        string $path,
        string $connectionName,
    ): array {
        $sidecar = $e->errorCode();
        $target = basename(str_replace('\\', '/', $path));
        if (in_array($sidecar, ['workspace_not_found', 'bad_file_name'], true)) {
            return [
                'status' => 404,
                'body' => [
                    'success' => false,
                    'code' => DestinationFailureCode::NotFound->value,
                    'error' => $sidecar,
                    'context' => ['target' => $target],
                ],
            ];
        }

        $code = match ($sidecar) {
            'workspace_quota_exceeded', 'capacity_exceeded' => DestinationFailureCode::QuotaExceeded->value,
            'workspace_busy' => DestinationFailureCode::Conflict->value,
            default => DestinationFailureCode::Unreachable->value,
        };

        return [
            'status' => 422,
            'body' => [
                'success' => false,
                'code' => $code,
                'error' => $sidecar,
                'context' => [
                    'target' => $target,
                    'connection' => $connectionName,
                ],
            ],
        ];
    }
}
