<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UseLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UseLogRepositoryAgentAggregateTest extends KernelTestCase
{
    public function testAggregateCountsUsersWithoutReturningIds(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $userA = $this->user($em, 'agent-usage-a@synaplan.internal');
        $userB = $this->user($em, 'agent-usage-b@synaplan.internal');
        $now = time();

        $em->getConnection()->executeStatement(
            'INSERT INTO BUSELOG (BUSERID, BUNIXTIMES, BACTION, BPROVIDER, BMODEL, BTOKENS, BPROMPT_TOKENS, BCOMPLETION_TOKENS, BCACHED_TOKENS, BCACHE_CREATION_TOKENS, BESTIMATED, BCOST, BLATENCY, BSTATUS, BERROR, BMETADATA)
             VALUES (:uid, :ts, :action, :provider, :model, :tokens, 0, 0, 0, 0, 0, :cost, 0, :status, :error, :meta)',
            [
                'uid' => $userA->getId(),
                'ts' => $now,
                'action' => 'MESSAGES',
                'provider' => 'test',
                'model' => 'test',
                'tokens' => 10,
                'cost' => '0.010000',
                'status' => 'success',
                'error' => '',
                'meta' => json_encode(['agentId' => 4242, 'agentVersionId' => 7]),
            ]
        );
        $em->getConnection()->executeStatement(
            'INSERT INTO BUSELOG (BUSERID, BUNIXTIMES, BACTION, BPROVIDER, BMODEL, BTOKENS, BPROMPT_TOKENS, BCOMPLETION_TOKENS, BCACHED_TOKENS, BCACHE_CREATION_TOKENS, BESTIMATED, BCOST, BLATENCY, BSTATUS, BERROR, BMETADATA)
             VALUES (:uid, :ts, :action, :provider, :model, :tokens, 0, 0, 0, 0, 0, :cost, 0, :status, :error, :meta)',
            [
                'uid' => $userB->getId(),
                'ts' => $now,
                'action' => 'MESSAGES',
                'provider' => 'test',
                'model' => 'test',
                'tokens' => 5,
                'cost' => '0.005000',
                'status' => 'success',
                'error' => '',
                'meta' => json_encode(['agentId' => 4242, 'agentVersionId' => 7]),
            ]
        );

        $repo = static::getContainer()->get(UseLogRepository::class);
        $out = $repo->aggregateForAgent(4242, $now - 10, $now + 10);

        self::assertNotEmpty($out['byVersion']);
        self::assertSame(2, $out['byVersion'][0]['distinctUsers']);
        self::assertSame(15, $out['byVersion'][0]['messages'] > 0 ? $out['byVersion'][0]['tokens'] : 0);
        $encoded = json_encode($out);
        self::assertIsString($encoded);
        self::assertStringNotContainsString((string) $userA->getId(), $encoded);
        self::assertStringNotContainsString((string) $userB->getId(), $encoded);
        self::assertArrayNotHasKey('userIds', $out['byVersion'][0]);
    }

    private function user(EntityManagerInterface $em, string $email): User
    {
        $existing = $em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }
        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('agent-usage-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
