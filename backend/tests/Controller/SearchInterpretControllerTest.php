<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Model;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\SmartSearch\SmartSearchConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SearchInterpretControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const CANDIDATES = [
        ['id' => 'page:/files', 'kind' => 'page', 'title' => 'Files', 'subtitle' => 'Sources'],
        ['id' => 'setting:FEATURE_IAM_GROUPS_ENABLED', 'kind' => 'setting', 'title' => 'FEATURE_IAM_GROUPS_ENABLED', 'subtitle' => 'Features › People & sharing', 'value' => 'false'],
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testRequiresAuthentication(): void
    {
        $this->postInterpret(['q' => 'turn on groups', 'candidates' => self::CANDIDATES]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testNoToolsModelRemovesTheSurface(): void
    {
        $this->authenticateClient($this->client, $this->createUser('interpret-nomodel@synaplan.internal'));

        $this->postInterpret(['q' => 'turn on groups', 'candidates' => self::CANDIDATES]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRejectsMissingCandidates(): void
    {
        $this->bindTestToolsModel();
        $this->authenticateClient($this->client, $this->createUser('interpret-validate@synaplan.internal'));

        $this->postInterpret(['q' => 'turn on groups']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->postInterpret(['q' => 'turn on groups', 'candidates' => [['id' => 'x:1', 'kind' => 'best', 'title' => 'Nope']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testPointsAtASentCandidate(): void
    {
        $this->bindTestToolsModel();
        $this->authenticateClient($this->client, $this->createUser('interpret-pick@synaplan.internal'));

        $body = $this->postInterpret(['q' => 'how do I turn on groups', 'language' => 'de', 'candidates' => self::CANDIDATES]);

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $body['outcome']);
        self::assertSame('change_setting', $body['intent']);
        self::assertSame(['setting:FEATURE_IAM_GROUPS_ENABLED'], $body['targetIds']);
        self::assertIsString($body['answer']);
    }

    public function testFlagOffRemovesTheSurface(): void
    {
        $this->bindTestToolsModel();
        $user = $this->createUser('interpret-off@synaplan.internal');
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, SmartSearchConfig::CONFIG_GROUP, SmartSearchConfig::KEY_AI_ENABLED, '0');
        $this->em->flush();
        $this->authenticateClient($this->client, $user);

        $this->postInterpret(['q' => 'turn on groups', 'candidates' => self::CANDIDATES]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('GET', '/api/v1/config/runtime');
        $runtime = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($runtime['features']['smartSearchAi']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function postInterpret(array $payload): array
    {
        $this->client->request(
            'POST',
            '/api/v1/search/interpret',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($payload),
        );

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function bindTestToolsModel(): void
    {
        $model = (new Model())
            ->setService('test')
            ->setName('Test Tools Model')
            ->setTag('chat')
            ->setSelectable(1)
            ->setProviderId('test-tools')
            ->setPriceIn(0)
            ->setPriceOut(0);
        $this->em->persist($model);
        $this->em->flush();

        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, 'DEFAULTMODEL', 'TOOLS', (string) $model->getId());
        $this->em->flush();
    }

    private function createUser(string $email): User
    {
        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('interpret-test-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
