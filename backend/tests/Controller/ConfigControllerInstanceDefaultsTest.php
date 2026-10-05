<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Model;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The AI Models page lets an admin choose the system-wide defaults that
 * guests and members without their own choice use. Reading them needs
 * `scope=instance`; a plain read stays the signed-in user's own defaults.
 */
final class ConfigControllerInstanceDefaultsTest extends WebTestCase
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

    public function testMemberCannotReadInstanceDefaults(): void
    {
        $member = $this->createUser('instance-defaults-member@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $member);

        $this->client->request('GET', '/api/v1/config/models/defaults?scope=instance');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAdminSavesAndReadsTheInstanceDefault(): void
    {
        $admin = $this->createUser('instance-defaults-admin@synaplan.internal', 'ADMIN');
        $instanceModelId = (int) $this->createChatModel('instance-defaults-everyone')->getId();
        $this->authenticateClient($this->client, $admin);

        $this->client->request('POST', '/api/v1/config/models/defaults', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'defaults' => ['CHAT' => $instanceModelId],
            'global' => true,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->request('GET', '/api/v1/config/models/defaults?scope=instance');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertSame($instanceModelId, $body['defaults']['CHAT']);
        self::assertSame('admin', $body['sources']['CHAT']);
        self::assertFalse($body['locked']['CHAT']);
    }

    private function createChatModel(string $providerId): Model
    {
        $model = (new Model())
            ->setService('test')
            ->setName('Instance '.$providerId)
            ->setTag('chat')
            ->setSelectable(1)
            ->setProviderId($providerId)
            ->setPriceIn(0)
            ->setPriceOut(0);
        $this->em->persist($model);
        $this->em->flush();

        return $model;
    }

    private function createUser(string $email, string $level): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            $existing->setUserLevel($level);
            $this->em->flush();

            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('instance-defaults-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
