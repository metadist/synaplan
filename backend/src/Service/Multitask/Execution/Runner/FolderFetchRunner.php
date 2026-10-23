<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\CloudPickup;
use App\Entity\Connection;
use App\Entity\File;
use App\Repository\ConnectionRepository;
use App\Service\Connection\PlannerChannel;
use App\Service\Connection\PlannerChannelCatalog;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Dav\DavConnectionResolver;
use App\Service\Dav\DavException;
use App\Service\Dav\DavTarget;
use App\Service\Dav\WebDavClient;
use App\Service\File\FileStorageService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\PlatformLink\PickupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads files from a connected folder into this run.
 *
 * A linked cloud is asked through the pickup protocol. A WebDAV folder
 * connection is read directly. The folder name is a channel folder, never
 * a path on this computer.
 */
final readonly class FolderFetchRunner implements TaskRunner
{
    public function __construct(
        private ConnectionRepository $connections,
        private CredentialVaultInterface $vault,
        private WebDavClient $webDav,
        private FileStorageService $storage,
        private EntityManagerInterface $em,
        private PickupService $pickups,
        private PlannerChannelCatalog $channels,
        #[Autowire('%env(int:PICKUP_WAIT_SEC)%')]
        private int $waitSeconds = 600,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::FolderFetch];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::FolderFetch,
                'Read files from one of the user\'s connected folders (Nextcloud, ownCloud.online, OpenCloud, or a WebDAV folder) and attach them to this run. params.channel is a folder name from the list below, params.folder is a folder inside it, params.pattern limits names (for example *.pdf), params.mode is list (names only) or fetch (the files). Read only. Never a path on this computer.',
                dynamicNote: fn (?int $userId, array $context): ?string => $this->renderAvailabilityNote($userId),
                requiresDynamicNote: true,
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $userId = $context->userId ?? (int) $context->message->getUserId();
        $folder = is_string($node->params['folder'] ?? null) ? trim($node->params['folder']) : '';
        $pattern = is_string($node->params['pattern'] ?? null) ? trim($node->params['pattern']) : '';
        $mode = 'list' === ($node->params['mode'] ?? null) ? CloudPickup::MODE_LIST : CloudPickup::MODE_FETCH;
        $channel = is_string($node->params['channel'] ?? null) ? trim($node->params['channel']) : '';

        $identityId = '' !== $channel ? $this->pickups->identityForChannel($userId, $channel, $this->usedChannels($userId)) : null;
        if (null !== $identityId) {
            return $this->fromCloud($userId, $identityId, $folder, $pattern, $mode, $channel);
        }

        $connection = $this->folderConnection($userId, $channel, $node->params['connection_id'] ?? null);
        if (!$connection instanceof Connection) {
            return NodeResult::failed('Synaplan has no connected folder to read. Nothing was read.');
        }

        return $this->fromWebDav($connection, $folder, $pattern, $mode);
    }

    private function fromCloud(int $userId, int $identityId, string $folder, string $pattern, string $mode, string $channel): NodeResult
    {
        $opened = $this->pickups->open($userId, $identityId, [
            'folder_path' => $folder,
            'pattern' => $pattern,
        ], $mode);
        if (!$opened['ok']) {
            return NodeResult::failed($opened['sentence']);
        }

        $deadline = microtime(true) + max(0, $this->waitSeconds);
        do {
            $pickup = $this->pickups->find($opened['pickup_id']);
            if ($pickup instanceof CloudPickup && $this->settled($pickup)) {
                return $this->fromPickup($pickup, $channel, $folder);
            }
            if ($this->waitSeconds <= 0) {
                break;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);

        return NodeResult::failed('Synaplan could not reach this cloud in time. Nothing was read.');
    }

    private function fromPickup(CloudPickup $pickup, string $channel, string $folder): NodeResult
    {
        $where = $this->where($channel, $folder);
        if (CloudPickup::STATE_FAILED === $pickup->getState()) {
            return NodeResult::failed($this->failedSentence($pickup, $where));
        }
        $files = CloudPickup::MODE_FETCH === $pickup->getMode() ? $this->pickups->producedFiles($pickup) : [];
        if (CloudPickup::MODE_FETCH === $pickup->getMode() && [] === $files) {
            return NodeResult::failed('No files in '.$where.' matched. Nothing was read.');
        }
        $text = $this->outcomeText($pickup, $where, count($files));
        if (CloudPickup::STATE_PARTIAL === $pickup->getState()) {
            return NodeResult::ok($text, $files, ['folder_fetch' => true, 'partial' => true]);
        }

        return NodeResult::ok($text, $files, ['folder_fetch' => true]);
    }

    private function fromWebDav(Connection $connection, string $folder, string $pattern, string $mode): NodeResult
    {
        $target = DavConnectionResolver::resolve($connection, $this->vault);
        $config = $connection->getConfig() ?? [];
        $root = is_string($config['folder'] ?? null) ? trim($config['folder'], '/') : '';
        $relative = '' !== $folder ? $folder : $root;
        $label = '' !== $connection->getName() ? $connection->getName() : 'the folder';
        if (null === $target) {
            return NodeResult::failed('Synaplan could not reach '.$label.'. Nothing was read.');
        }

        try {
            $listed = $this->webDav->list($target, $relative);
        } catch (DavException $e) {
            if (404 === $e->statusCode) {
                return NodeResult::failed($label.' has no folder '.$relative.' that you can open. Nothing was read.');
            }

            return NodeResult::failed('Synaplan could not reach '.$target->host().'. Nothing was read.');
        }

        $matched = [];
        foreach ($listed as $row) {
            if ($this->nameMatches($pattern, $row['name'])) {
                $matched[] = $row;
            }
        }
        if (CloudPickup::MODE_LIST === $mode) {
            if ([] === $matched) {
                return NodeResult::failed('No files in '.$relative.' matched. Nothing was read.');
            }
            $lines = [];
            foreach ($matched as $row) {
                $lines[] = $row['name'];
            }

            return NodeResult::ok(implode("\n", $lines), [], ['folder_fetch' => true, 'mode' => 'list']);
        }

        return $this->downloadWebDav($connection, $target, $matched, $relative, $label);
    }

    /**
     * @param list<array{name: string, path: string, size: int}> $matched
     */
    private function downloadWebDav(Connection $connection, DavTarget $target, array $matched, string $relative, string $label): NodeResult
    {
        $files = [];
        $skippedLarge = 0;
        $skippedLimit = 0;
        $bytes = 0;
        $stopped = false;
        foreach ($matched as $row) {
            if ($row['size'] > PickupService::MAX_FILE_BYTES) {
                ++$skippedLarge;
                continue;
            }
            if (count($files) >= PickupService::MAX_FILES || $bytes + $row['size'] > PickupService::MAX_BYTES) {
                ++$skippedLimit;
                continue;
            }
            try {
                $body = $this->webDav->get($target, $row['path'], PickupService::MAX_FILE_BYTES);
            } catch (DavException $e) {
                if (str_contains($e->getMessage(), 'larger than the limit')) {
                    ++$skippedLarge;
                    continue;
                }
                $stopped = true;
                break;
            }
            $stored = $this->storeWorkingCopy($connection, $body, $row['name'], $row['path']);
            if (null === $stored) {
                $stopped = true;
                break;
            }
            $files[] = $stored;
            $bytes += strlen($body);
        }
        $where = $this->where($label, $relative);
        if ([] === $files) {
            return NodeResult::failed($this->nothingRead($where, $skippedLarge, $skippedLimit));
        }
        if ($skippedLarge > 0 || $skippedLimit > 0 || $stopped) {
            return NodeResult::ok(
                $this->partialSentence($where, count($files), count($files) + $skippedLarge + $skippedLimit, $skippedLarge, $skippedLimit, $stopped),
                $files,
                ['folder_fetch' => true, 'partial' => true],
            );
        }

        return NodeResult::ok('Read '.count($files).' files from '.$where.'.', $files, ['folder_fetch' => true]);
    }

    /**
     * @return array{path: string, type: string, local_path: string, name: string}|null
     */
    private function storeWorkingCopy(Connection $connection, string $body, string $name, string $remoteId): ?array
    {
        $ownerId = $connection->getOwnerId();
        $stored = $this->storage->storeRawContent($body, $ownerId, '' !== $name ? $name : 'file', 'application/octet-stream');
        if (true !== $stored['success'] || '' === $stored['path']) {
            return null;
        }
        $file = new File();
        $file->setUserId($ownerId);
        $file->setFilePath($stored['path']);
        $file->setFileName($name);
        $file->setFileSize($stored['size']);
        $file->setFileMime('application/octet-stream');
        $file->setFileType(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');
        $file->setStatus('complete');
        $config = $connection->getConfig() ?? [];
        $channel = is_string($config['channel'] ?? null) ? $config['channel'] : '';
        $file->setSource(in_array($channel, File::SOURCES, true) ? $channel : 'api');
        $file->setSourceId(strlen($remoteId) <= 255 ? $remoteId : null);
        $file->setIncoming(false);
        $file->setEphemeral(true);
        $this->em->persist($file);
        $this->em->flush();

        return [
            'path' => '/api/v1/files/uploads/'.$stored['path'],
            'type' => 'document',
            'local_path' => $stored['path'],
            'name' => $name,
        ];
    }

    private function folderConnection(int $userId, string $channel, mixed $connectionId): ?Connection
    {
        if ('' !== $channel) {
            $found = $this->channels->find($userId, $channel);
            if ($found instanceof PlannerChannel && PlannerChannel::KIND_FOLDER === $found->kind) {
                $connectionId = $found->connectionId;
            }
        }
        if (!is_numeric($connectionId)) {
            return null;
        }
        $connection = $this->connections->findByIdAndOwner((int) $connectionId, $userId);

        return $connection instanceof Connection && 'webdav' === $connection->getType() ? $connection : null;
    }

    private function settled(CloudPickup $pickup): bool
    {
        return in_array($pickup->getState(), [
            CloudPickup::STATE_DONE,
            CloudPickup::STATE_PARTIAL,
            CloudPickup::STATE_FAILED,
        ], true);
    }

    private function where(string $channel, string $folder): string
    {
        $name = '' !== $channel ? $channel : 'the folder';

        return '' !== $folder ? $name.' › '.$folder : $name;
    }

    private function failedSentence(CloudPickup $pickup, string $where): string
    {
        return match ($pickup->getErrorCode()) {
            'outside_scope' => 'Synaplan may not read '.$where.'. Nothing was read.',
            'not_found' => $where.' has no folder you can open. Nothing was read.',
            default => 'Synaplan could not reach '.$where.'. Nothing was read.',
        };
    }

    private function outcomeText(CloudPickup $pickup, string $where, int $attached): string
    {
        if (CloudPickup::MODE_LIST === $pickup->getMode()) {
            $names = $pickup->names();

            return [] === $names ? 'Read the names from '.$where.'.' : implode("\n", $names);
        }
        $large = 0;
        $over = 0;
        foreach ($pickup->skipped() as $row) {
            if ('too_large' === ($row['reason'] ?? null)) {
                ++$large;
            }
            if ('over_limit' === ($row['reason'] ?? null)) {
                ++$over;
            }
        }
        if ($large > 0 || $over > 0) {
            return $this->partialSentence($where, $attached, max($pickup->getListed(), $attached + $large + $over), $large, $over, false);
        }

        return 'Read '.$attached.' files from '.$where.'.';
    }

    private function nothingRead(string $where, int $large, int $over): string
    {
        if ($large > 0 && 0 === $over) {
            return 'Every file in '.$where.' is larger than 100 MB. Nothing was read.';
        }
        if ($over > 0) {
            return $where.' has more files than one run can take. Nothing was read.';
        }

        return 'No files in '.$where.' matched. Nothing was read.';
    }

    private function partialSentence(string $where, int $read, int $total, int $large, int $over, bool $stopped): string
    {
        $why = [];
        if ($large > 0) {
            $why[] = $large.' '.($large > 1 ? 'were' : 'was').' larger than 100 MB and left out';
        }
        if ($over > 0) {
            $why[] = $over.' '.($over > 1 ? 'were' : 'was').' past the limit for one run and left out';
        }
        if ($stopped) {
            $why[] = 'the rest could not be read';
        }

        return 'Read '.$read.' of '.$total.' files from '.$where.$this->because($why).'.';
    }

    /**
     * @param list<string> $reasons
     */
    private function because(array $reasons): string
    {
        if ([] === $reasons) {
            return '';
        }
        if (1 === count($reasons)) {
            return '; '.$reasons[0];
        }
        $last = array_pop($reasons);

        return '; '.implode(', ', $reasons).', and '.$last;
    }

    /**
     * @return list<string>
     */
    private function usedChannels(int $userId): array
    {
        try {
            $used = [];
            foreach ($this->channels->forUser($userId) as $channel) {
                $used[] = $channel->key;
            }

            return $used;
        } catch (\Throwable) {
            return [];
        }
    }

    private function nameMatches(string $pattern, string $name): bool
    {
        if ('' === $pattern || '*' === $pattern) {
            return true;
        }
        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/i';

        return 1 === preg_match($regex, $name);
    }

    /**
     * SkillCatalogFactory builds runners without constructors. A missing
     * dependency means "no connected folder", not a crash.
     */
    private function renderAvailabilityNote(?int $userId): ?string
    {
        if (null === $userId || $userId <= 0) {
            return null;
        }
        try {
            $lines = [];
            $used = [];
            foreach ($this->channels->ofKind($userId, PlannerChannel::KIND_FOLDER) as $channel) {
                $connection = $this->connections->findByIdAndOwner($channel->connectionId, $userId);
                if (!$connection instanceof Connection || 'webdav' !== $connection->getType()) {
                    continue;
                }
                $used[] = $channel->key;
                $lines[] = sprintf('  - "%s": connected folder %s. params.channel="%s".', $channel->key, $channel->label, $channel->key);
            }
            foreach ($this->pickups->readableLinks($userId, $used) as $link) {
                $folders = [] === $link['folders'] ? 'only what they watch' : implode(', ', $link['folders']);
                $lines[] = sprintf(
                    '  - "%s": linked %s at %s. Synaplan may read: %s. params.channel="%s".',
                    $link['channel'],
                    $link['client'],
                    $link['host'],
                    $folders,
                    $link['channel'],
                );
            }
        } catch (\Throwable) {
            return null;
        }

        return [] === $lines ? null : implode("\n", $lines);
    }
}
