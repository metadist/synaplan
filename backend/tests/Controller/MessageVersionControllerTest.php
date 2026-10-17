<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Message\MessageVersionService;
use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/v1/messages/{id}/turn-link and /select-version: an Again answer
 * joins the versions of its turn, the version the user picks is stored, and
 * a message of another account is not found.
 */
final class MessageVersionControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testAnAgainAnswerJoinsTheTurnAndThePickedVersionIsStored(): void
    {
        $owner = $this->createUser('versions-owner');
        [$first, $again] = $this->twoAnswers($owner);
        $this->authenticateClient($this->client, $owner);

        $this->post('/api/v1/messages/'.$again->getId().'/turn-link', ['kind' => 'again', 'previousId' => $first->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('/api/v1/messages/'.$first->getId().'/select-version', ['kind' => 'answer']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $first = $this->em->find(Message::class, $first->getId());
        $again = $this->em->find(Message::class, $again->getId());
        self::assertNotNull($first);
        self::assertNotNull($again);
        $group = (string) $first->getId();
        self::assertSame([$group, $group], [$first->getMeta(MessageVersionService::VERSION_GROUP), $again->getMeta(MessageVersionService::VERSION_GROUP)]);
        self::assertSame(['1', '0'], [$first->getMeta(MessageVersionService::VERSION_SELECTED), $again->getMeta(MessageVersionService::VERSION_SELECTED)]);
    }

    public function testAMessageOfAnotherAccountIsNotFound(): void
    {
        [$first, $again] = $this->twoAnswers($this->createUser('versions-owner'));
        $this->authenticateClient($this->client, $this->createUser('versions-stranger'));

        $this->post('/api/v1/messages/'.$again->getId().'/turn-link', ['kind' => 'again', 'previousId' => $first->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->post('/api/v1/messages/'.$first->getId().'/select-version', ['kind' => 'answer']);
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        self::assertNull($this->em->find(Message::class, $again->getId())?->getMeta(MessageVersionService::VERSION_GROUP));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $uri, array $body): void
    {
        $this->client->request('POST', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    }

    /**
     * @return array{Message, Message} an answer and its Again, in one chat
     */
    private function twoAnswers(User $owner): array
    {
        $chat = $this->persistEntity((new Chat())->setUserId((int) $owner->getId())->setSource('web')->setTitle('Versions'));

        return [$this->answer($owner, $chat, 'The first answer'), $this->answer($owner, $chat, 'The answer again')];
    }

    private function answer(User $owner, Chat $chat, string $text): Message
    {
        return $this->persistEntity((new Message())
            ->setUserId((int) $owner->getId())
            ->setChat($chat)
            ->setTrackingId(1)
            ->setProviderIndex('WEB')
            ->setUnixTimestamp(time())
            ->setDateTime(date('YmdHis'))
            ->setMessageType('WEB')
            ->setFile(0)
            ->setTopic('CHAT')
            ->setLanguage('en')
            ->setText($text)
            ->setDirection('OUT')
            ->setStatus('complete'));
    }
}
