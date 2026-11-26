<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Prompt;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `tools:` prompts are the system's own instructions. The list hides them;
 * reading one by id must not be a way around that.
 */
final class PromptControllerInternalTopicTest extends WebTestCase
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

    public function testAUserCannotReadAnInternalPromptById(): void
    {
        $internal = $this->systemPrompt('tools:internal_'.bin2hex(random_bytes(4)));
        $this->authenticateClient($this->client, $this->user('NEW'));

        $this->client->request('GET', '/api/v1/prompts/'.$internal->getId());

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('internal instructions', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAdminStillReadsIt(): void
    {
        $internal = $this->systemPrompt('tools:internal_'.bin2hex(random_bytes(4)));
        $this->authenticateClient($this->client, $this->user('ADMIN'));

        $this->client->request('GET', '/api/v1/prompts/'.$internal->getId());

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testAUserStillReadsAnOrdinarySystemPrompt(): void
    {
        $public = $this->systemPrompt('helper_'.bin2hex(random_bytes(4)));
        $this->authenticateClient($this->client, $this->user('NEW'));

        $this->client->request('GET', '/api/v1/prompts/'.$public->getId());

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    private function user(string $level): User
    {
        $user = (new User())
            ->setMail('internal-topic-'.bin2hex(random_bytes(4)).'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId('internal-topic-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function systemPrompt(string $topic): Prompt
    {
        $prompt = new Prompt();
        $prompt->setOwnerId(0);
        $prompt->setLanguage('en');
        $prompt->setTopic($topic);
        $prompt->setShortDescription('System prompt');
        $prompt->setPrompt('These are internal instructions.');
        $this->em->persist($prompt);
        $this->em->flush();

        return $prompt;
    }
}
