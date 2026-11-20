<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Chat\Loop\ChatRunCostLimit;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The person's own cost limit for one long chat answer: only they read and
 * change it, and only to one of the offered shares.
 */
final class ChatRunLimitControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const URL = '/api/v1/config/chat-run-limit';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ConfigRepository $config;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->config = static::getContainer()->get(ConfigRepository::class);
    }

    public function testWithoutASignInNothingIsReadOrChanged(): void
    {
        $this->client->request('GET', self::URL);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $this->put(['percent' => 50]);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testThePersonSeesTheDefaultShareAndTheChoices(): void
    {
        $user = $this->user('chat-run-limit-get@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->client->request('GET', self::URL);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertSame(ChatRunCostLimit::DEFAULT_PERCENT, $body['percent'] ?? null);
        self::assertSame(ChatRunCostLimit::DEFAULT_PERCENT, $body['default'] ?? null);
        self::assertSame(ChatRunCostLimit::CHOICES, $body['choices'] ?? null);
        self::assertArrayHasKey('enabled', $body);
        self::assertArrayHasKey('monthlyBudget', $body);
        self::assertArrayHasKey('perRun', $body);
    }

    public function testAChosenShareIsSavedForThatPersonOnly(): void
    {
        $user = $this->user('chat-run-limit-put@synaplan.internal');
        $other = $this->user('chat-run-limit-other@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->put(['percent' => 25]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(25, $this->json()['percent'] ?? null);
        self::assertSame('25', $this->config->getValue((int) $user->getId(), ChatRunCostLimit::GROUP, ChatRunCostLimit::SETTING));
        self::assertNull($this->config->getValue((int) $other->getId(), ChatRunCostLimit::GROUP, ChatRunCostLimit::SETTING));

        $this->put(['percent' => null]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(ChatRunCostLimit::DEFAULT_PERCENT, $this->json()['percent'] ?? null);
        self::assertNull($this->config->getValue((int) $user->getId(), ChatRunCostLimit::GROUP, ChatRunCostLimit::SETTING));
    }

    public function testAShareThatIsNotOfferedIsRefused(): void
    {
        $user = $this->user('chat-run-limit-bad@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->put(['percent' => 150]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());

        $this->put(['percent' => '25']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());

        $this->put([]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());

        self::assertNull($this->config->getValue((int) $user->getId(), ChatRunCostLimit::GROUP, ChatRunCostLimit::SETTING));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(array $body): void
    {
        $this->client->request(
            'PUT',
            self::URL,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode((object) $body, \JSON_THROW_ON_ERROR),
        );
    }

    private function user(string $email): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            $this->config->deleteValue((int) $existing->getId(), ChatRunCostLimit::GROUP, ChatRunCostLimit::SETTING);

            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('chat-run-limit-'.uniqid())
            ->setUserLevel('PRO');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
