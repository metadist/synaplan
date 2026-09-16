<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Agent\AgentConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class WidgetControllerAgentIdTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->ensureDefaultPromptExists();
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED, '0');
        $this->em->flush();
    }

    public function testBindAgentIdIs400WhenAssistantsOff(): void
    {
        $user = $this->createUser('widget-agent-off@synaplan.internal');
        $this->authenticateClient($this->client, $user);
        $this->client->request(
            'POST',
            '/api/v1/widgets',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Pinned widget'], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $widgetId = json_decode((string) $this->client->getResponse()->getContent(), true)['widget']['widgetId'];

        $this->client->request(
            'PUT',
            '/api/v1/widgets/'.$widgetId,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['agentId' => 99], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertStringContainsString('Assistants are not enabled', (string) ($body['error'] ?? ''));
    }

    private function ensureDefaultPromptExists(): void
    {
        $existing = $this->em->getRepository(Prompt::class)
            ->findOneBy(['topic' => 'tools:widget-default', 'ownerId' => 0]);
        if ($existing instanceof Prompt) {
            return;
        }
        $prompt = new Prompt();
        $prompt->setOwnerId(0);
        $prompt->setLanguage('en');
        $prompt->setTopic('tools:widget-default');
        $prompt->setShortDescription('Default widget prompt for tests');
        $prompt->setPrompt('You are a helpful assistant.');
        $this->em->persist($prompt);
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
            ->setProviderId('widget-agent-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
