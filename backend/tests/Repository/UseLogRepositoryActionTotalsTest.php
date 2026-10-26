<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UseLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The usage board's agent chip counts only requests made by an agent: an
 * ordinary reply records `agentId: null`, and a zero id is no agent either —
 * the same rule as {@see \App\Service\Usage\UsageKind::hasAgent()}.
 */
final class UseLogRepositoryActionTotalsTest extends KernelTestCase
{
    private const EMAIL = 'usage-action-totals@synaplan.internal';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testOnlyPositiveAgentIdsCountAsAgentUse(): void
    {
        $user = $this->user();
        $now = time();
        $this->log($user, $now, ['agentId' => null]);
        $this->log($user, $now, ['agentId' => 0]);
        $this->log($user, $now, ['agent_id' => 0]);
        $this->log($user, $now, []);
        $this->log($user, $now, ['agentId' => 7]);
        $this->log($user, $now, ['agent_id' => '9']);

        $rows = static::getContainer()->get(UseLogRepository::class)->actionTotalsForUsers([(int) $user->getId()], $now - 10);

        $byAgent = [];
        foreach ($rows as $row) {
            $byAgent[(int) $row['has_agent']] = (int) $row['requests'];
        }
        self::assertSame([0 => 4, 1 => 2], [0 => $byAgent[0] ?? 0, 1 => $byAgent[1] ?? 0]);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM BUSELOG WHERE BUSERID IN (SELECT BID FROM BUSER WHERE BMAIL = ?)', [self::EMAIL]);
        $this->em->getConnection()->executeStatement('DELETE FROM BUSER WHERE BMAIL = ?', [self::EMAIL]);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function log(User $user, int $at, array $metadata): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO BUSELOG (BUSERID, BUNIXTIMES, BACTION, BPROVIDER, BMODEL, BTOKENS, BPROMPT_TOKENS, BCOMPLETION_TOKENS, BCACHED_TOKENS, BCACHE_CREATION_TOKENS, BESTIMATED, BCOST, BLATENCY, BSTATUS, BERROR, BMETADATA)
             VALUES (?, ?, ?, ?, ?, 10, 0, 0, 0, 0, 0, ?, 0, ?, ?, ?)',
            [$user->getId(), $at, 'MESSAGES', 'test', 'test', '0.001000', 'success', '', json_encode($metadata)],
        );
    }

    private function user(): User
    {
        $this->em->getConnection()->executeStatement('DELETE FROM BUSELOG WHERE BUSERID IN (SELECT BID FROM BUSER WHERE BMAIL = ?)', [self::EMAIL]);
        $this->em->getConnection()->executeStatement('DELETE FROM BUSER WHERE BMAIL = ?', [self::EMAIL]);
        $user = (new User())->setMail(self::EMAIL)->setType('WEB')->setProviderId('usage-totals-'.uniqid())->setUserLevel('NEW');
        $user->setCreated(date('Y-m-d H:i:s'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
