<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A cloud that registers again — after a lost state file, a reinstall — is
 * the same cloud: same host, same OIDC client. The newer registration takes
 * over everything the older one had, so nobody has to connect again and no
 * watch or searchable file is lost; the older instance is then revoked.
 *
 * Deterministic and safe under concurrent announcements: an instance only
 * ever takes over instances registered *before* it, each one is locked and
 * re-read inside the transaction, and an older twin that announces first
 * finds nothing to do.
 */
final readonly class PlatformInstanceSupersession
{
    /** Tables that name a cloud by its instance id and must follow the takeover. */
    private const INSTANCE_COLUMNS = [
        'BFILES' => 'BSOURCEINSTANCE',
        'BCLOUDWATCHFEED' => 'BINSTANCEID',
        'BCLOUDWATCHTOMBSTONES' => 'BINSTANCEID',
        'BCLOUDPICKUPS' => 'BINSTANCEID',
        'BCLOUDPICKUPFILES' => 'BINSTANCEID',
    ];

    public function __construct(
        private PlatformInstanceRepository $platforms,
        private ExternalIdentityRepository $identities,
        private ApiKeyRepository $apiKeys,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private AuditLogWriter $auditLog,
    ) {
    }

    /**
     * Lets the given active instance take over every older active instance
     * of the same host and OIDC client. Returns the ids it superseded.
     *
     * @return list<string>
     */
    public function takeOverOlderTwins(PlatformInstance $newer): array
    {
        $clientId = $newer->getOidcClientId();
        if (null === $clientId || '' === $clientId || !$newer->isActive() || null === $newer->getId()) {
            return [];
        }
        $candidates = array_filter(
            $this->platforms->findBy(['oidcClientId' => $clientId, 'host' => $newer->getHost()]),
            static fn (PlatformInstance $other): bool => $other->isActive()
                && null !== $other->getId()
                && $other->getId() < $newer->getId(),
        );
        $superseded = [];
        foreach ($candidates as $older) {
            if ($this->takeOver($newer, $older)) {
                $superseded[] = $older->getInstanceId();
            }
        }

        return $superseded;
    }

    private function takeOver(PlatformInstance $newer, PlatformInstance $older): bool
    {
        return (bool) $this->entityManager->wrapInTransaction(function () use ($newer, $older): bool {
            // Another announcement may have taken this twin over already.
            $this->entityManager->lock($older, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($older);
            if (!$older->isActive()) {
                return false;
            }

            [$moved, $merged] = $this->moveIdentities($newer, $older);
            foreach (self::INSTANCE_COLUMNS as $table => $column) {
                $this->connection->executeStatement(
                    sprintf('UPDATE %s SET %s = :newer WHERE %s = :older', $table, $column, $column),
                    ['newer' => $newer->getInstanceId(), 'older' => $older->getInstanceId()],
                );
            }

            $older->setStatus(PlatformInstance::STATUS_REVOKED);
            $this->platforms->save($older, false);
            $this->entityManager->flush();

            $this->auditLog->record(
                $newer->getRegisteredBy(),
                'platform_instance.superseded',
                'platform_instance',
                $older->getInstanceId(),
                [
                    'by' => $newer->getInstanceId(),
                    'host' => $newer->getHost(),
                    'identities_moved' => $moved,
                    'identities_merged' => $merged,
                ],
            );

            return true;
        });
    }

    /**
     * Each person's identity row follows to the newer instance, with its
     * key, so their watches and away-key keep working. A person who already
     * connected to the newer instance keeps that row: the older one and its
     * key are removed, and the watches that hung on it move over.
     *
     * @return array{int, int} identities moved, identities merged
     */
    private function moveIdentities(PlatformInstance $newer, PlatformInstance $older): array
    {
        $onNewer = [];
        foreach ($this->identities->findByInstanceId($newer->getInstanceId()) as $identity) {
            $onNewer[$identity->getExternalId()] = $identity;
        }
        $moved = 0;
        $merged = 0;
        foreach ($this->identities->findByInstanceId($older->getInstanceId()) as $identity) {
            $twin = $onNewer[$identity->getExternalId()] ?? null;
            if (!$twin instanceof ExternalIdentity) {
                $identity->setInstanceId($newer->getInstanceId());
                $this->identities->save($identity, false);
                ++$moved;
                continue;
            }
            $this->connection->executeStatement(
                'UPDATE BCLOUDWATCHES SET BIDENTITYID = :twin WHERE BIDENTITYID = :older',
                ['twin' => (int) $twin->getId(), 'older' => (int) $identity->getId()],
            );
            $keyId = $identity->getApiKeyId();
            if (null !== $keyId) {
                $key = $this->apiKeys->find($keyId);
                if (null !== $key) {
                    $this->apiKeys->remove($key, false);
                }
            }
            $this->identities->remove($identity, false);
            ++$merged;
        }

        return [$moved, $merged];
    }
}
