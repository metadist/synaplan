<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\UseLog;
use App\Entity\User;
use App\Repository\MailHandlerLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailHandlerLogRepositoryTest extends KernelTestCase
{
    private const HANDLER_ID = 987654;

    private EntityManagerInterface $em;
    private MailHandlerLogRepository $repository;
    private int $userId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(MailHandlerLogRepository::class);

        $user = new User();
        $user->setMail('mailhandlerlog_'.bin2hex(random_bytes(4)).'@test.com');
        $user->setPw('test123');
        $user->setProviderId('TEST');
        $user->setUserLevel('NEW');
        $this->em->persist($user);
        $this->em->flush();
        $this->userId = (int) $user->getId();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $this->repository->deleteAll($this->userId, self::HANDLER_ID);
        $connection->executeStatement('DELETE FROM BUSER WHERE BID = ?', [$this->userId]);
        parent::tearDown();
    }

    public function testSavedEntryIsReadBack(): void
    {
        $this->repository->save($this->userId, self::HANDLER_ID, $this->entry('forwarded'));

        $rows = $this->repository->findRecent($this->userId, self::HANDLER_ID, 10);

        $this->assertCount(1, $rows);
        $this->assertSame('warning', $rows[0]['status']);
        $this->assertSame('SMTP missing', $rows[0]['error']);
        $this->assertSame('forwarded', json_decode($rows[0]['metadata'], true)['event']);
        $this->assertTrue($this->em->isOpen());
    }

    public function testSaveDoesNotFlushOtherPendingEntities(): void
    {
        $pending = $this->entry('pending');
        $pending->setUserId($this->userId);
        $pending->setAction(MailHandlerLogRepository::ACTION);
        $pending->setProvider((string) self::HANDLER_ID);
        $this->em->persist($pending);

        $this->repository->save($this->userId, self::HANDLER_ID, $this->entry('check'));

        $rows = $this->repository->findRecent($this->userId, self::HANDLER_ID, 10);
        $this->assertCount(1, $rows);
        $this->assertSame('check', json_decode($rows[0]['metadata'], true)['event']);

        $this->em->detach($pending);
    }

    private function entry(string $event): UseLog
    {
        $entry = new UseLog();
        $entry->setUnixTimestamp(time());
        $entry->setStatus('warning');
        $entry->setError('SMTP missing');
        $entry->setMetadata(['event' => $event]);

        return $entry;
    }
}
