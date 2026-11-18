<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\SearchResult;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Chat\ChatActionPolicy;
use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A reply downloads as a file of its owner only, in the three formats, and
 * not at all when chat export is turned off.
 */
final class MessageExportControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;
    private ?int $exportSwitchOwner = null;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->exportSwitchOwner) {
            static::getContainer()->get(ConfigRepository::class)
                ->deleteValue($this->exportSwitchOwner, ChatActionPolicy::GROUP, ChatActionPolicy::EXPORT);
        }
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testAnonymousIsUnauthorized(): void
    {
        [, $reply] = $this->conversation($this->createUser('export-owner'));

        $this->client->request('GET', $this->url($reply));

        self::assertResponseStatusCodeSame(401);
    }

    public function testExportTurnedOffIsForbidden(): void
    {
        $owner = $this->createUser('export-owner');
        [, $reply] = $this->conversation($owner);
        $this->exportSwitchOwner = (int) $owner->getId();
        static::getContainer()->get(ConfigRepository::class)
            ->setValue($this->exportSwitchOwner, ChatActionPolicy::GROUP, ChatActionPolicy::EXPORT, '0');
        $this->authenticateClient($this->client, $owner);

        $this->client->request('GET', $this->url($reply));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAReplyOfAnotherAccountIsNotFound(): void
    {
        [, $reply] = $this->conversation($this->createUser('export-owner'), 'Secret findings');
        $this->authenticateClient($this->client, $this->createUser('export-stranger'));

        $this->client->request('GET', $this->url($reply));

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Secret findings', (string) $this->client->getResponse()->getContent());
    }

    public function testTheQuestionItselfAndAMissingMessageAreNotFound(): void
    {
        $owner = $this->createUser('export-owner');
        [$question] = $this->conversation($owner);
        $this->authenticateClient($this->client, $owner);

        $this->client->request('GET', $this->url($question));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/messages/999999999/export');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownFormatIsABadRequest(): void
    {
        $owner = $this->createUser('export-owner');
        [, $reply] = $this->conversation($owner);
        $this->authenticateClient($this->client, $owner);

        $this->client->request('GET', $this->url($reply, 'pdf'));

        self::assertResponseStatusCodeSame(400);
    }

    public function testEachFormatDownloadsWithItsTypeAndName(): void
    {
        $owner = $this->createUser('export-owner');
        [, $reply] = $this->conversation($owner);
        $this->authenticateClient($this->client, $owner);
        $expected = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'html' => 'text/html; charset=utf-8',
            'md' => 'text/markdown; charset=utf-8',
        ];

        foreach ($expected as $format => $type) {
            $this->client->request('GET', $this->url($reply, $format));
            $response = $this->client->getResponse();

            self::assertResponseIsSuccessful();
            self::assertSame($type, $response->headers->get('Content-Type'));
            self::assertSame('attachment; filename=which-heat-pump-fits-an-old-house.'.$format, $response->headers->get('Content-Disposition'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
    }

    public function testDocxIsTheDefaultAndMarkdownHoldsTitleReplyAndSources(): void
    {
        $owner = $this->createUser('export-owner');
        [, $reply] = $this->conversation($owner);
        $this->authenticateClient($this->client, $owner);

        $this->client->request('GET', '/api/v1/messages/'.$reply->getId().'/export');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('PK', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', $this->url($reply, 'md'));
        $markdown = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("# Which heat pump fits an old house?\n\nAir-to-water fits best [1].", $markdown);
        self::assertStringContainsString("## Sources\n\n1. Heat pump guide — https://example.org/guide", $markdown);
    }

    /**
     * @return array{Message, Message} the question and the reply
     */
    private function conversation(User $owner, string $replyText = 'Air-to-water fits best [1].'): array
    {
        $chat = $this->persistEntity((new Chat())->setUserId((int) $owner->getId())->setSource('web')->setTitle('Export'));
        $now = time();
        $question = $this->message($owner, $chat, 'IN', "Which heat pump fits an old house?\nIt is from 1930.", $now - 5);
        $reply = $this->message($owner, $chat, 'OUT', $replyText, $now);
        $this->persistEntity((new SearchResult())
            ->setMessage($question)
            ->setQuery('heat pump')
            ->setTitle('Heat pump guide')
            ->setUrl('https://example.org/guide')
            ->setSource('example.org')
            ->setPosition(1));

        return [$question, $reply];
    }

    private function message(User $owner, Chat $chat, string $direction, string $text, int $at): Message
    {
        return $this->persistEntity((new Message())
            ->setUserId((int) $owner->getId())
            ->setChat($chat)
            ->setTrackingId($at)
            ->setProviderIndex('WEB')
            ->setUnixTimestamp($at)
            ->setDateTime(date('YmdHis', $at))
            ->setMessageType('WEB')
            ->setFile(0)
            ->setTopic('CHAT')
            ->setLanguage('en')
            ->setText($text)
            ->setDirection($direction)
            ->setStatus('complete'));
    }

    private function url(Message $message, string $format = 'docx'): string
    {
        return '/api/v1/messages/'.$message->getId().'/export?format='.$format;
    }
}
