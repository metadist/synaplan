<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GettingStartedControllerTest extends WebTestCase
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

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $this->client->request('GET', '/api/v1/getting-started');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testSignedInUserGetsStepFlagsAndNoProviderStep(): void
    {
        $user = (new User())
            ->setMail('getting-started-'.uniqid().'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId('getting-started-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->authenticateClient($this->client, $user);

        $this->client->request('GET', '/api/v1/getting-started');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success'] ?? false);
        self::assertIsArray($body['items'] ?? null);
        self::assertIsBool($body['items']['file'] ?? null);
        self::assertIsBool($body['items']['app'] ?? null);
        self::assertArrayNotHasKey('provider', $body['items']);
    }
}
