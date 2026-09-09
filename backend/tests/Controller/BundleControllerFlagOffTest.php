<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Bundle\BundleConfig;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class BundleControllerFlagOffTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, BundleConfig::CONFIG_GROUP, BundleConfig::KEY_ENABLED, '0');
        $this->em->flush();
    }

    public function testSectionsIs404WhenFlagOff(): void
    {
        $user = $this->createUser('bundle-off@synaplan.internal');
        $this->authenticateClient($this->client, $user);
        $this->client->request('GET', '/api/v1/bundle/sections');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testImportIs404WhenFlagOff(): void
    {
        $user = $this->createUser('bundle-off-import@synaplan.internal');
        $this->authenticateClient($this->client, $user);
        $this->client->request(
            'POST',
            '/api/v1/bundle/import',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"schema":"synaplan-bundle.v1"}',
        );
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIs401Not404(): void
    {
        $this->client->request('GET', '/api/v1/bundle/sections');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
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
            ->setProviderId('bundle-off-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
