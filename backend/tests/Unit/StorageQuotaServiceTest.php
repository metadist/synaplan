<?php

namespace App\Tests\Unit;

use App\Entity\Config;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\FileRepository;
use App\Service\BillingService;
use App\Service\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StorageQuotaServiceTest extends TestCase
{
    private FileRepository $fileRepository;
    private ConfigRepository $configRepository;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;
    private StorageQuotaService $service;

    protected function setUp(): void
    {
        $this->fileRepository = $this->createMock(FileRepository::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $billingService = new BillingService('sk_test_valid_key', 'price_1RealProId');

        $this->service = new StorageQuotaService(
            $this->fileRepository,
            $this->configRepository,
            $this->em,
            $this->logger,
            $billingService
        );
    }

    private function createUser(string $level = 'FREE'): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getRateLimitLevel')->willReturn($level);

        return $user;
    }

    public function testGetStorageLimitReturnsGBForPaidPlans(): void
    {
        $user = $this->createUser('PRO');

        $config = $this->createMock(Config::class);
        $config->method('getValue')->willReturn('10'); // 10 GB

        $this->configRepository
            ->method('findOneBy')
            ->willReturn($config);

        $limit = $this->service->getStorageLimit($user);

        // 10 GB = 10 * 1024 * 1024 * 1024 bytes
        $this->assertEquals(10 * 1024 * 1024 * 1024, $limit);
    }

    public function testGetStorageLimitReturnsMBForFreePlans(): void
    {
        $user = $this->createUser('FREE');

        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(function ($criteria) {
                if ('STORAGE_GB' === $criteria['setting']) {
                    return null; // No GB config
                }
                if ('STORAGE_MB' === $criteria['setting']) {
                    $config = $this->createMock(Config::class);
                    $config->method('getValue')->willReturn('100'); // 100 MB

                    return $config;
                }

                return null;
            });

        $limit = $this->service->getStorageLimit($user);

        // 100 MB = 100 * 1024 * 1024 bytes
        $this->assertEquals(100 * 1024 * 1024, $limit);
    }

    public function testGetStorageLimitReturnsDefaultWhenNoConfig(): void
    {
        $user = $this->createUser('NEW');

        $this->configRepository
            ->method('findOneBy')
            ->willReturn(null);

        $limit = $this->service->getStorageLimit($user);

        // Default: 100 MB
        $this->assertEquals(100 * 1024 * 1024, $limit);
    }

    public function testGetStorageUsageReturnsZeroWhenNoFiles(): void
    {
        $user = $this->createUser();

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(null);

        $qb->method('getQuery')->willReturn($query);

        $this->fileRepository
            ->method('createQueryBuilder')
            ->willReturn($qb);

        $usage = $this->service->getStorageUsage($user);

        $this->assertEquals(0, $usage);
    }

    public function testGetStorageUsageReturnsCorrectSum(): void
    {
        $user = $this->createUser();
        $totalSize = 5242880; // 5 MB

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn($totalSize);

        $qb->method('getQuery')->willReturn($query);

        $this->fileRepository
            ->method('createQueryBuilder')
            ->willReturn($qb);

        $usage = $this->service->getStorageUsage($user);

        $this->assertEquals($totalSize, $usage);
    }

    public function testGetRemainingStorageReturnsCorrectValue(): void
    {
        $user = $this->createUser('FREE');

        // Mock limit: 100 MB
        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(function ($criteria) {
                if ('STORAGE_MB' === $criteria['setting']) {
                    $config = $this->createMock(Config::class);
                    $config->method('getValue')->willReturn('100');

                    return $config;
                }

                return null;
            });

        // Mock usage: 40 MB
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(40 * 1024 * 1024);

        $qb->method('getQuery')->willReturn($query);

        $this->fileRepository
            ->method('createQueryBuilder')
            ->willReturn($qb);

        $remaining = $this->service->getRemainingStorage($user);

        // 100 MB - 40 MB = 60 MB
        $this->assertEquals(60 * 1024 * 1024, $remaining);
    }

    public function testGetRemainingStorageReturnsZeroWhenLimitExceeded(): void
    {
        $user = $this->createUser('FREE');

        // Mock limit: 100 MB
        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(function ($criteria) {
                if ('STORAGE_MB' === $criteria['setting']) {
                    $config = $this->createMock(Config::class);
                    $config->method('getValue')->willReturn('100');

                    return $config;
                }

                return null;
            });

        // Mock usage: 150 MB (exceeds limit)
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(150 * 1024 * 1024);

        $qb->method('getQuery')->willReturn($query);

        $this->fileRepository
            ->method('createQueryBuilder')
            ->willReturn($qb);

        $remaining = $this->service->getRemainingStorage($user);

        $this->assertEquals(0, $remaining);
    }

    public function testHasStorageForReturnsTrueWhenEnoughSpace(): void
    {
        $user = $this->createUser('FREE');
        $fileSize = 10 * 1024 * 1024; // 10 MB

        // Mock limit: 100 MB
        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(function ($criteria) {
                if ('STORAGE_MB' === $criteria['setting']) {
                    $config = $this->createMock(Config::class);
                    $config->method('getValue')->willReturn('100');

                    return $config;
                }

                return null;
            });

        // Mock usage: 40 MB
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(40 * 1024 * 1024);

        $qb->method('getQuery')->willReturn($query);

        $this->fileRepository
            ->method('createQueryBuilder')
            ->willReturn($qb);

        $result = $this->service->hasStorageFor($user, $fileSize);

        $this->assertTrue($result);
    }

    public function testHasStorageForReturnsFalseWhenNotEnoughSpace(): void
    {
        $user = $this->createUser('FREE');
        $fileSize = 70 * 1024 * 1024; // 70 MB

        // Mock limit: 100 MB
        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(function ($criteria) {
                if ('STORAGE_MB' === $criteria['setting']) {
                    $config = $this->createMock(Config::class);
                    $config->method('getValue')->willReturn('100');

                    return $config;
                }

                return null;
            });

        // Mock usage: 90 MB
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(90 * 1024 * 1024);

        $qb->method('getQuery')->willReturn($query);

        $this->fileRepository
            ->method('createQueryBuilder')
            ->willReturn($qb);

        $result = $this->service->hasStorageFor($user, $fileSize);

        $this->assertFalse($result);
    }
}
