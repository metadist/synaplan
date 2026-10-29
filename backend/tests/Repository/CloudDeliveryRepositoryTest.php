<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CloudDelivery;
use App\Repository\CloudDeliveryRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CloudDeliveryRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        if ([] !== $this->ids) {
            $connection->executeStatement(
                'DELETE FROM BCLOUDDELIVERIES WHERE BID IN (?)',
                [$this->ids],
                [ArrayParameterType::INTEGER],
            );
        }
        parent::tearDown();
    }

    public function testSentRequiresTheDownloadAndTheFirstResultWins(): void
    {
        $repo = $this->repo();
        $delivery = $this->persist(time());
        $id = (int) $delivery->getId();

        self::assertFalse($repo->recordDelivered($id, 'path', 'remote', 'https://files.example.org/f/1', 'unconfirmed'));
        self::assertSame(CloudDelivery::STATE_QUEUED, $delivery->getState());
        self::assertTrue($repo->claimIfQueued($id));
        self::assertSame(CloudDelivery::STATE_CLAIMED, $delivery->getState());
        self::assertFalse($repo->claimIfQueued($id));
        self::assertFalse($repo->recordDelivered($id, 'path', 'remote', 'https://files.example.org/f/1', 'unconfirmed'));
        self::assertSame(CloudDelivery::STATE_CLAIMED, $delivery->getState());
        self::assertTrue($repo->markDownloaded($id));
        self::assertTrue($delivery->wasDownloaded());
        self::assertFalse($repo->markDownloaded($id));
        self::assertTrue($repo->recordDelivered($id, 'Synaplan/Documents/notes.txt', 'remote-1', 'https://files.example.org/f/1', 'unconfirmed'));
        self::assertFalse($repo->recordDelivered($id, 'other.txt', 'remote-2', null, 'unconfirmed'));
        self::assertFalse($repo->recordFailed($id, 'conflict', false));

        $this->em->clear();
        $fresh = $repo->find($id);
        self::assertInstanceOf(CloudDelivery::class, $fresh);
        self::assertSame(CloudDelivery::STATE_DELIVERED, $fresh->getState());
        self::assertSame('Synaplan/Documents/notes.txt', $fresh->getRemotePath());
        self::assertTrue($fresh->wasDownloaded());
    }

    public function testAQueuedResultCannotFailTheRowButANotifyCan(): void
    {
        $repo = $this->repo();
        $delivery = $this->persist(time());
        $id = (int) $delivery->getId();

        self::assertFalse($repo->recordFailed($id, 'quota', false));
        self::assertSame(CloudDelivery::STATE_QUEUED, $delivery->getState());
        self::assertTrue($repo->recordFailed($id, 'quota', true));
        self::assertSame(CloudDelivery::STATE_FAILED, $delivery->getState());
        self::assertSame('quota', $delivery->getErrorCode());
    }

    public function testAClaimedRowAgesOutAndADownloadedFailureCanStillBeSent(): void
    {
        $repo = $this->repo();
        $oldClaimed = $this->persist(1);
        $claimedId = (int) $oldClaimed->getId();
        self::assertTrue($repo->claimIfQueued($claimedId));

        $oldQueued = $this->persist(1);
        $queuedId = (int) $oldQueued->getId();

        $recent = $this->persist(time());
        $recentId = (int) $recent->getId();
        self::assertTrue($repo->claimIfQueued($recentId));

        $downloaded = $this->persist(time());
        $downloadedId = (int) $downloaded->getId();
        self::assertTrue($repo->claimIfQueued($downloadedId));
        self::assertTrue($repo->markDownloaded($downloadedId));
        self::assertTrue($repo->recordFailed($downloadedId, 'unconfirmed', false));
        self::assertFalse($repo->recordDelivered($downloadedId, 'nope', null, null, 'quota'));
        self::assertTrue($repo->recordDelivered($downloadedId, 'Synaplan/notes.txt', 'r', null, 'unconfirmed'));

        $repo->failOpenOlderThan(time() - 100, 'unconfirmed');
        $this->em->clear();

        $claimed = $repo->find($claimedId);
        $queued = $repo->find($queuedId);
        $stillOpen = $repo->find($recentId);
        $sent = $repo->find($downloadedId);
        self::assertInstanceOf(CloudDelivery::class, $claimed);
        self::assertInstanceOf(CloudDelivery::class, $queued);
        self::assertInstanceOf(CloudDelivery::class, $stillOpen);
        self::assertInstanceOf(CloudDelivery::class, $sent);
        self::assertSame(CloudDelivery::STATE_FAILED, $claimed->getState());
        self::assertSame('unconfirmed', $claimed->getErrorCode());
        self::assertSame(CloudDelivery::STATE_FAILED, $queued->getState());
        self::assertSame('unconfirmed', $queued->getErrorCode());
        self::assertSame(CloudDelivery::STATE_CLAIMED, $stillOpen->getState());
        self::assertSame(CloudDelivery::STATE_DELIVERED, $sent->getState());
        self::assertSame('Synaplan/notes.txt', $sent->getRemotePath());
    }

    private function repo(): CloudDeliveryRepository
    {
        $repo = static::getContainer()->get(CloudDeliveryRepository::class);
        self::assertInstanceOf(CloudDeliveryRepository::class, $repo);

        return $repo;
    }

    private function persist(int $created): CloudDelivery
    {
        $delivery = new CloudDelivery(7, 3, 4, CloudDelivery::TARGET_KIND_FOLDER, null, CloudDelivery::CONFLICT_RENAME);
        (new \ReflectionProperty(CloudDelivery::class, 'created'))->setValue($delivery, $created);
        $this->repo()->save($delivery);
        $this->ids[] = (int) $delivery->getId();

        return $delivery;
    }
}
