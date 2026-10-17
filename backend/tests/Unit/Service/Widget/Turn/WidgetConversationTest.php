<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget\Turn;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Repository\ChatRepository;
use App\Repository\FileRepository;
use App\Service\Widget\Turn\WidgetConversation;
use App\Service\Widget\Turn\WidgetTurn;
use App\Service\Widget\WidgetHandoff;
use App\Service\Widget\WidgetRealtimeBroadcaster;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WidgetConversationTest extends TestCase
{
    /** @var list<mixed> what the stage did, in order */
    private array $steps = [];
    /** @var array<int, Chat> */
    private array $chats = [];
    /** @var array<int, File> */
    private array $files = [];
    private WidgetSession $session;

    protected function setUp(): void
    {
        $this->session = (new WidgetSession())->setWidgetId('wdg_1')->setSessionId('sess_abcdef');
        (new \ReflectionProperty(WidgetSession::class, 'id'))->setValue($this->session, 99);
    }

    public function testTheSessionsOwnChatIsReused(): void
    {
        $this->session->setChatId(5);
        $this->chats[5] = $this->chat(7);

        $stored = $this->conversation()->store($this->turn());

        self::assertSame($this->chats[5], $stored->chat);
        self::assertNotContains(['persist', 'chat'], $this->steps);
    }

    public function testAChatOfAnotherOwnerIsNeverUsed(): void
    {
        $this->session->setChatId(5);
        $this->chats[5] = $this->chat(8);
        $this->chats[6] = $this->chat(7);

        self::assertSame($this->chats[6], $this->conversation()->store($this->turn(chatId: 6))->chat);
    }

    public function testANewChatOpensWithTheWelcomeBeforeTheVisitorsMessage(): void
    {
        $stored = $this->conversation()->store($this->turn(['autoMessage' => 'Welcome!']));

        self::assertSame('Widget: Shop • abcdef', $stored->chat->getTitle());
        self::assertSame(
            [['persist', 'chat'], ['flush'], ['persist', 'OUT', 'Welcome!'], ['flush'], ['flush'], ['persist', 'IN', 'Hi'], ['flush']],
            array_slice($this->steps, 0, 7),
        );
        self::assertSame(1, $this->session->getMessageCount());
    }

    public function testATriggerPhrasePagesAPersonOnlyInAnAiSession(): void
    {
        $config = ['slackWebhookUrl' => 'https://hooks.slack.com/x', 'humanHandoffTriggers' => ['refund', 7, 'human']];

        $this->conversation()->store($this->turn($config, text: 'I want a HUMAN please'));
        $this->session->setMode(WidgetSession::MODE_WAITING);
        $this->conversation()->store($this->turn($config, text: 'I want a HUMAN please'));

        self::assertSame([['page', 'https://hooks.slack.com/x', 'I want a HUMAN please', 'matched phrase: "human"']], array_values(array_filter(
            $this->steps,
            static fn (array $step): bool => 'page' === $step[0],
        )));
    }

    public function testOnlyTheSessionsOwnUploadsAreAttached(): void
    {
        $this->files[11] = (new File())->setUserSessionId(99);
        $this->files[12] = (new File())->setUserSessionId(100);

        $stored = $this->conversation()->store($this->turn(files: [11, 12, 'x', -1, 13]));

        self::assertSame([11], $stored->fileIds);
        self::assertSame([1, 'attached', 'uploaded'], [$stored->message->getFile(), $this->files[11]->getStatus(), $this->files[12]->getStatus()]);
    }

    public function testTheCounterPreviewAndChatLinkShareTheLastFlush(): void
    {
        $this->conversation()->store($this->turn());

        self::assertSame([['increment', false], ['attach_chat', false], ['flush']], array_slice($this->steps, -3));
        self::assertSame('Hi', $this->session->getLastMessagePreview());
    }

    public function testAMessageForAPersonWaitsAndReachesEveryOperator(): void
    {
        $this->session->setMode(WidgetSession::MODE_HUMAN);
        $conversation = $this->conversation();
        $turn = $this->turn(text: str_repeat('a', 120));
        $stored = $conversation->store($turn);
        $this->steps = [];

        $answer = $conversation->awaitOperator($turn, $stored);

        self::assertSame(['success' => true, 'chatId' => null, 'mode' => WidgetSession::MODE_WAITING, 'text' => ''], $answer);
        self::assertSame('complete', $stored->message->getStatus());
        self::assertSame([['flush'], ['operators', 'new_message', 'sess_abcdef', 100], ['title']], $this->steps);
    }

    private function conversation(): WidgetConversation
    {
        $chats = $this->createStub(ChatRepository::class);
        $chats->method('find')->willReturnCallback(fn (int $id): ?Chat => $this->chats[$id] ?? null);
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturnCallback(fn (int $id): ?File => $this->files[$id] ?? null);

        $sessions = $this->createStub(WidgetSessionService::class);
        $sessions->method('incrementMessageCount')->willReturnCallback(function (WidgetSession $session, bool $flush): void {
            $this->steps[] = ['increment', $flush];
        });
        $sessions->method('attachChat')->willReturnCallback(function (WidgetSession $session, Chat $chat, bool $flush): void {
            $this->steps[] = ['attach_chat', $flush];
        });
        $sessions->method('generateTitleIfNeeded')->willReturnCallback(function (): void {
            $this->steps[] = ['title'];
        });

        $broadcaster = $this->createStub(WidgetRealtimeBroadcaster::class);
        $broadcaster->method('publishOperatorNotification')->willReturnCallback(function (string $widgetId, array $payload): void {
            $this->steps[] = ['operators', $payload['kind'], $payload['sessionId'], mb_strlen($payload['preview'])];
        });

        $handoff = $this->createStub(WidgetHandoff::class);
        $handoff->method('page')->willReturnCallback(function (Widget $widget, WidgetSession $session, string $webhookUrl, ?string $lastMessage, string $reason): bool {
            $this->steps[] = ['page', $webhookUrl, $lastMessage, $reason];

            return true;
        });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->steps[] = $entity instanceof Message ? ['persist', $entity->getDirection(), $entity->getText()] : ['persist', 'chat'];
        });
        $em->method('flush')->willReturnCallback(function (): void {
            $this->steps[] = ['flush'];
        });

        return new WidgetConversation($chats, $files, $sessions, $broadcaster, $handoff, $em, new NullLogger());
    }

    /**
     * @param array<string, mixed> $config
     * @param array<mixed>         $files
     */
    private function turn(array $config = [], string $text = 'Hi', array $files = [], ?int $chatId = null): WidgetTurn
    {
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(7);

        return new WidgetTurn((new Widget())->setWidgetId('wdg_1')->setName('Shop'), $owner, $this->session, $config, $text, $files, $chatId, '', false);
    }

    private function chat(int $userId): Chat
    {
        return (new Chat())->setUserId($userId)->setTitle('Existing');
    }
}
