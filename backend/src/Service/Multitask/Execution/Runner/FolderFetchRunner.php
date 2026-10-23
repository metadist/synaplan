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
use App\Service\PlatformLink\CloudFolderLink;
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
    /** Names the step text lists for the answer. */
    private const NAMES_IN_TEXT = 50;

    public function __construct(
        private ConnectionRepository $connections,
        private CredentialVaultInterface $vault,
        private WebDavClient $webDav,
        private FileStorageService $storage,
        private EntityManagerInterface $em,
        private PickupService $pickups,
        private PlannerChannelCatalog $channels,
        private CloudFolderLink $folderLinks,
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

        $link = '' !== $channel ? $this->pickups->linkForChannel($userId, $channel, $this->usedChannels($userId)) : null;
        if (null !== $link) {
            $source = new FolderRead(
                $link['client'],
                FolderRead::clientLabel($link['client']),
                $link['host'],
                $folder,
                $this->folderLinks->to($link['client'], $link['host'], $folder),
            );

            return $this->fromCloud($node->id, $context, $userId, $link['identity_id'], $source, $pattern, $mode);
        }

        $connection = $this->folderConnection($userId, $channel, $node->params['connection_id'] ?? null);
        if (!$connection instanceof Connection) {
            $nowhere = new FolderRead('', '', '', $folder);

            return NodeResult::failed('Synaplan has no connected folder to read. Nothing was read.', $nowhere->metadata(FolderRead::NOT_CONNECTED));
        }

        return $this->fromWebDav($node->id, $context, $connection, $folder, $pattern, $mode);
    }

    private function fromCloud(string $nodeId, NodeContext $context, int $userId, int $identityId, FolderRead $source, string $pattern, string $mode): NodeResult
    {
        $opened = $this->pickups->open($userId, $identityId, [
            'folder_path' => $source->folder,
            'pattern' => $pattern,
        ], $mode);
        if (!$opened['ok']) {
            $outcome = match ($opened['error']) {
                'outside_scope' => FolderRead::REFUSED,
                'rate_limited' => FolderRead::BUSY,
                'not_linked', 'unauthorized' => FolderRead::NOT_CONNECTED,
                default => FolderRead::UNREACHABLE,
            };

            return NodeResult::failed($opened['sentence'], $source->metadata($outcome));
        }

        $deadline = microtime(true) + max(0, $this->waitSeconds);
        $reported = '';
        do {
            $pickup = $this->pickups->find($opened['pickup_id']);
            if ($pickup instanceof CloudPickup && $this->settled($pickup)) {
                return $this->fromPickup($pickup, $source);
            }
            if ($pickup instanceof CloudPickup) {
                $reported = $this->reportProgress($context, $nodeId, $source, count($pickup->fileIds()), $this->matchingSoFar($pickup), $reported);
            }
            if ($this->waitSeconds <= 0) {
                break;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);

        if (!$this->pickups->giveUp($opened['pickup_id'])) {
            // The cloud's result landed between the last look and giving up.
            $pickup = $this->pickups->find($opened['pickup_id']);
            if ($pickup instanceof CloudPickup && $this->settled($pickup)) {
                return $this->fromPickup($pickup, $source);
            }
        }

        return NodeResult::failed('Synaplan could not reach '.$this->hostOf($source).' in time. Nothing was read.', $source->metadata(FolderRead::UNREACHABLE));
    }

    /**
     * The files of this folder the run asked for: the ones it takes plus the
     * ones it leaves out for size or the run limit ("12 of 52").
     */
    private function matchingSoFar(CloudPickup $pickup): int
    {
        $leftOut = 0;
        foreach ($pickup->skipped() as $row) {
            if (in_array($row['reason'] ?? null, ['too_large', 'over_limit'], true)) {
                ++$leftOut;
            }
        }

        return $pickup->acceptCount() + $leftOut;
    }

    private function fromPickup(CloudPickup $pickup, FolderRead $source): NodeResult
    {
        $where = $source->where();
        $skipped = $this->skippedOf($pickup);
        if (CloudPickup::STATE_FAILED === $pickup->getState()) {
            return match ($pickup->getErrorCode()) {
                'outside_scope' => NodeResult::failed('Synaplan may not read '.$where.'. Nothing was read.', $source->metadata(FolderRead::REFUSED)),
                'not_found' => NodeResult::failed($where.' has no folder you can open. Nothing was read.', $source->metadata(FolderRead::NOT_FOUND)),
                default => NodeResult::failed('Synaplan could not reach '.$this->hostOf($source).'. Nothing was read.', $source->metadata(FolderRead::UNREACHABLE)),
            };
        }
        if (CloudPickup::MODE_LIST === $pickup->getMode()) {
            $names = $pickup->names();
            if ([] === $names) {
                return NodeResult::failed('No files in '.$where.' matched. Nothing was read.', $source->metadata(FolderRead::NOTHING));
            }

            return NodeResult::ok(implode("\n", $names), [], $source->metadata(FolderRead::LISTED, count($names), count($names), $names) + ['mode' => 'list']);
        }
        $files = $this->pickups->producedFiles($pickup);
        $names = array_map(static fn (array $file): string => $file['name'], $files);
        if ([] === $files) {
            return NodeResult::failed($this->nothingRead($where, $skipped), $source->metadata(FolderRead::NOTHING, 0, count($skipped), [], $skipped));
        }
        $total = count($files) + count($skipped);
        if ([] !== $skipped || CloudPickup::STATE_PARTIAL === $pickup->getState()) {
            return NodeResult::ok(
                $this->partialSentence($where, $names, $total, $skipped),
                $files,
                $source->metadata(FolderRead::PARTIAL, count($files), $total, $names, $skipped) + ['partial' => true],
            );
        }

        return NodeResult::ok($this->readSentence($where, $names), $files, $source->metadata(FolderRead::READ, count($files), $total, $names));
    }

    private function fromWebDav(string $nodeId, NodeContext $context, Connection $connection, string $folder, string $pattern, string $mode): NodeResult
    {
        $target = DavConnectionResolver::resolve($connection, $this->vault);
        $config = $connection->getConfig() ?? [];
        $root = is_string($config['folder'] ?? null) ? trim($config['folder'], '/') : '';
        $relative = '' !== $folder ? $folder : $root;
        $label = '' !== $connection->getName() ? $connection->getName() : 'the folder';
        $source = new FolderRead('webdav', $label, null !== $target ? $target->host() : '', $relative);
        if (null === $target) {
            return NodeResult::failed('Synaplan could not reach '.$label.'. Nothing was read.', $source->metadata(FolderRead::UNREACHABLE));
        }

        try {
            $listed = $this->webDav->list($target, $relative);
        } catch (DavException $e) {
            if (404 === $e->statusCode) {
                return NodeResult::failed($label.' has no folder '.$relative.' that you can open. Nothing was read.', $source->metadata(FolderRead::NOT_FOUND));
            }

            return NodeResult::failed('Synaplan could not reach '.$target->host().'. Nothing was read.', $source->metadata(FolderRead::UNREACHABLE));
        }

        $matched = array_values(array_filter($listed, fn (array $row): bool => $this->nameMatches($pattern, $row['name'])));
        if (CloudPickup::MODE_LIST === $mode) {
            if ([] === $matched) {
                return NodeResult::failed('No files in '.$source->where().' matched. Nothing was read.', $source->metadata(FolderRead::NOTHING));
            }
            $names = array_map(static fn (array $row): string => $row['name'], $matched);

            return NodeResult::ok(implode("\n", $names), [], $source->metadata(FolderRead::LISTED, count($names), count($names), $names) + ['mode' => 'list']);
        }

        return $this->downloadWebDav($nodeId, $context, $connection, $target, $matched, $source);
    }

    /**
     * @param list<array{name: string, path: string, size: int}> $matched
     */
    private function downloadWebDav(string $nodeId, NodeContext $context, Connection $connection, DavTarget $target, array $matched, FolderRead $source): NodeResult
    {
        $files = [];
        $names = [];
        $skipped = [];
        $bytes = 0;
        $unreachable = false;
        $reported = '';
        foreach ($matched as $row) {
            if ($unreachable) {
                // Once the folder stops answering, the rest is named, not dropped.
                $skipped[] = ['name' => $row['name'], 'reason' => 'unreadable'];
                continue;
            }
            if ($row['size'] > PickupService::MAX_FILE_BYTES) {
                $skipped[] = ['name' => $row['name'], 'reason' => 'too_large'];
                continue;
            }
            if (count($files) >= PickupService::MAX_FILES || $bytes + $row['size'] > PickupService::MAX_BYTES) {
                $skipped[] = ['name' => $row['name'], 'reason' => 'over_limit'];
                continue;
            }
            $reported = $this->reportProgress($context, $nodeId, $source, count($files), count($matched), $reported);
            try {
                $body = $this->webDav->get($target, $row['path'], PickupService::MAX_FILE_BYTES);
            } catch (DavException $e) {
                $tooLarge = str_contains($e->getMessage(), 'larger than the limit');
                $skipped[] = ['name' => $row['name'], 'reason' => $tooLarge ? 'too_large' : 'unreadable'];
                $unreachable = !$tooLarge;
                continue;
            }
            $stored = $this->storeWorkingCopy($connection, $body, $row['name'], $row['path']);
            if (null === $stored) {
                $skipped[] = ['name' => $row['name'], 'reason' => 'unreadable'];
                continue;
            }
            $files[] = $stored;
            $names[] = $row['name'];
            $bytes += strlen($body);
        }
        $where = $source->where();
        $total = count($matched);
        if ([] === $files) {
            return NodeResult::failed(
                $unreachable ? 'Synaplan could not reach '.$target->host().'. Nothing was read.' : $this->nothingRead($where, $skipped),
                $source->metadata($unreachable ? FolderRead::UNREACHABLE : FolderRead::NOTHING, 0, $total, [], $skipped),
            );
        }
        if ([] !== $skipped) {
            return NodeResult::ok(
                $this->partialSentence($where, $names, $total, $skipped),
                $files,
                $source->metadata(FolderRead::PARTIAL, count($files), $total, $names, $skipped) + ['partial' => true],
            );
        }

        return NodeResult::ok($this->readSentence($where, $names), $files, $source->metadata(FolderRead::READ, count($files), $total, $names));
    }

    /**
     * Sends "Reading files from … n of m" to the card when the count moved.
     */
    private function reportProgress(NodeContext $context, string $nodeId, FolderRead $source, int $read, int $total, string $reported): string
    {
        $now = $read.'/'.$total;
        if ($now !== $reported) {
            $context->emitProgress($nodeId, ['folder_read' => $source->metadata(FolderRead::READING, $read, $total)['folder_read']]);
        }

        return $now;
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

    /**
     * @return list<array{name: string, reason: string}>
     */
    private function skippedOf(CloudPickup $pickup): array
    {
        $out = [];
        foreach ($pickup->skipped() as $row) {
            $reason = is_string($row['reason'] ?? null) ? $row['reason'] : '';
            if ('type_not_read' === $reason) {
                continue;
            }
            $name = is_string($row['name'] ?? null) && '' !== $row['name'] ? $row['name'] : (is_string($row['id'] ?? null) ? $row['id'] : '');
            $out[] = ['name' => $name, 'reason' => '' !== $reason ? $reason : 'unreadable'];
        }

        return $out;
    }

    private function settled(CloudPickup $pickup): bool
    {
        return in_array($pickup->getState(), [CloudPickup::STATE_DONE, CloudPickup::STATE_PARTIAL, CloudPickup::STATE_FAILED], true);
    }

    private function hostOf(FolderRead $source): string
    {
        return '' !== $source->host ? $source->host : 'this cloud';
    }

    /**
     * @param list<string> $names
     */
    private function readSentence(string $where, array $names): string
    {
        return 'Read '.count($names).' files from '.$where.$this->nameList($names).'.';
    }

    /**
     * @param list<array{name: string, reason: string}> $skipped
     */
    private function nothingRead(string $where, array $skipped): string
    {
        $reasons = array_unique(array_column($skipped, 'reason'));
        if (['too_large'] === array_values($reasons)) {
            return 'Every file in '.$where.' is larger than 100 MB. Nothing was read.';
        }
        if (in_array('over_limit', $reasons, true)) {
            return $where.' has more files than one run can take. Nothing was read.';
        }

        return 'No files in '.$where.' matched. Nothing was read.';
    }

    /**
     * @param list<string>                              $names
     * @param list<array{name: string, reason: string}> $skipped
     */
    private function partialSentence(string $where, array $names, int $total, array $skipped): string
    {
        $large = count(array_filter($skipped, static fn (array $row): bool => 'too_large' === $row['reason']));
        $over = count(array_filter($skipped, static fn (array $row): bool => 'over_limit' === $row['reason']));
        $other = count($skipped) - $large - $over;
        $why = [];
        if ($large > 0) {
            $why[] = $large.' '.($large > 1 ? 'were' : 'was').' larger than 100 MB and left out';
        }
        if ($over > 0) {
            $why[] = $over.' '.($over > 1 ? 'were' : 'was').' past the limit for one run and left out';
        }
        if ($other > 0) {
            $why[] = $other.' could not be read';
        }

        return 'Read '.count($names).' of '.max($total, count($names)).' files from '.$where.$this->nameList($names).$this->because($why).'.';
    }

    /**
     * @param list<string> $names
     */
    private function nameList(array $names): string
    {
        if ([] === $names) {
            return '';
        }
        $shown = array_slice($names, 0, self::NAMES_IN_TEXT);
        $rest = count($names) - count($shown);

        return ': '.implode(', ', $shown).($rest > 0 ? ' and '.$rest.' more' : '');
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
            foreach ($this->channels->ofKind($userId, PlannerChannel::KIND_FOLDER) as $channel) {
                $connection = $this->connections->findByIdAndOwner($channel->connectionId, $userId);
                if (!$connection instanceof Connection || 'webdav' !== $connection->getType()) {
                    continue;
                }
                $lines[] = sprintf('  - "%s": connected folder %s. params.channel="%s".', $channel->key, $channel->label, $channel->key);
            }
            foreach ($this->pickups->readableLinks($userId, $this->usedChannels($userId)) as $link) {
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
