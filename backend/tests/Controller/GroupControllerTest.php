<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GroupControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testMineIs404WhenFlagOff(): void
    {
        $this->disableFlag();
        $user = $this->createUser('iam-mine-off@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->client->request('GET', '/api/v1/groups/mine');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testMineListsMembershipsWhenFlagOn(): void
    {
        $this->enableFlag();
        $user = $this->createUser('iam-mine-on@synaplan.internal');
        $group = new Group();
        $group->setName('Sales');
        $group->setSlug('sales-mine-'.uniqid());
        $this->em->persist($group);
        $this->em->flush();

        $member = new GroupMember((int) $group->getId(), (int) $user->getId());
        $member->setRole(GroupMember::ROLE_MEMBER);
        $this->em->persist($member);
        $this->em->flush();

        $this->authenticateClient($this->client, $user);
        $this->client->request('GET', '/api/v1/groups/mine');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $groups = json_decode((string) $this->client->getResponse()->getContent(), true)['groups'];
        self::assertCount(1, $groups);
        self::assertSame('Sales', $groups[0]['name']);
        self::assertSame('member', $groups[0]['role']);
    }

    public function testIamReadKeyCanListMine(): void
    {
        $this->enableFlag();
        $user = $this->createUser('iam-mine-read-key@synaplan.internal');
        $group = new Group();
        $group->setName('Sales');
        $group->setSlug('sales-read-key-'.uniqid());
        $this->em->persist($group);
        $this->em->flush();
        $member = new GroupMember((int) $group->getId(), (int) $user->getId());
        $this->em->persist($member);
        $this->em->flush();

        $plain = 'sk_'.bin2hex(random_bytes(16));
        $apiKey = (new \App\Entity\ApiKey())
            ->setOwner($user)
            ->setKey($plain)
            ->setStatus('active')
            ->setName('IAM read key')
            ->setScopes([\App\Security\ApiKeyScope::IAM_READ]);
        $this->em->persist($apiKey);
        $this->em->flush();

        $this->client->request('GET', '/api/v1/groups/mine', server: [
            'HTTP_X_API_KEY' => $plain,
        ]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $groups = json_decode((string) $this->client->getResponse()->getContent(), true)['groups'];
        self::assertCount(1, $groups);
        self::assertSame('Sales', $groups[0]['name']);
    }

    private function enableFlag(): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '1');
        $this->em->flush();
    }

    private function disableFlag(): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '0');
        $this->em->flush();
    }

    private function createUser(string $email): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('iam-mine-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
