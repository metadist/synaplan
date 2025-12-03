<?php

namespace App\Tests\Repository;

use App\Entity\EmailBlacklist;
use App\Repository\EmailBlacklistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\AbstractQuery;
use PHPUnit\Framework\TestCase;

class EmailBlacklistRepositoryTest extends TestCase
{
    private EmailBlacklistRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        
        // We need to partially mock the repository since it extends ServiceEntityRepository
        $this->repository = $this->getMockBuilder(EmailBlacklistRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder', 'getEntityManager'])
            ->getMock();

        $this->repository->method('getEntityManager')
            ->willReturn($this->em);
    }

    public function testIsBlacklisted_ReturnsTrueWhenEmailExists(): void
    {
        $this->markTestSkipped('Complex Doctrine QueryBuilder mocking - covered by integration tests');
    }

    public function testIsBlacklisted_ReturnsFalseWhenEmailNotExists(): void
    {
        $this->markTestSkipped('Complex Doctrine QueryBuilder mocking - covered by integration tests');
    }


    public function testIsBlacklisted_NormalizesEmail(): void
    {
        $this->markTestSkipped('Complex Doctrine QueryBuilder mocking - covered by integration tests');
    }


    public function testAddToBlacklist_CreatesNewEntry(): void
    {
        $email = 'spam@evil.com';
        $reason = 'Too many requests';
        $blacklistedBy = 123;

        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($entry) use ($email, $reason, $blacklistedBy) {
                $this->assertInstanceOf(EmailBlacklist::class, $entry);
                $this->assertEquals($email, $entry->getEmail());
                $this->assertEquals($reason, $entry->getReason());
                $this->assertEquals($blacklistedBy, $entry->getBlacklistedBy());
                return true;
            }));

        $this->em->expects($this->once())
            ->method('flush');

        $result = $this->repository->addToBlacklist($email, $reason, $blacklistedBy);
        
        $this->assertInstanceOf(EmailBlacklist::class, $result);
    }

    public function testRemoveFromBlacklist_DeletesEntry(): void
    {
        $this->markTestSkipped('Complex Doctrine QueryBuilder mocking - covered by integration tests');
    }

}

