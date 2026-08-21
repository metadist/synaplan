<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat;

use App\AI\Service\AiFacade;
use App\Entity\Chat;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Chat\ChatTitleService;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Web chats used to store the raw truncated first user message as their title,
 * so a "Hi" turned into a sidebar entry called "Hi" (#1500). These tests cover
 * the replacement: an AI-generated label, generated once, never over a title
 * the user chose, and never at the cost of the turn that triggered it.
 */
final class ChatTitleServiceTest extends TestCase
{
    private const SUMMARY_CONFIG = [
        'model' => 'llama3.2',
        'provider' => 'ollama',
        'model_id' => 42,
    ];

    private AiFacade&MockObject $aiFacade;
    private MessageRepository&MockObject $messageRepository;
    private EntityManagerInterface&MockObject $em;

    private function createService(?string $aiReply = 'Invoice import problem'): ChatTitleService
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        if (null === $aiReply) {
            $this->aiFacade->method('chat')->willThrowException(new \RuntimeException('provider down'));
        } else {
            $this->aiFacade->method('chat')->willReturn([
                'content' => $aiReply,
                'provider' => 'ollama',
                'model' => 'llama3.2',
                'usage' => [],
            ]);
        }

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getSummaryModelConfig')->willReturn(self::SUMMARY_CONFIG);

        return new ChatTitleService(
            $this->aiFacade,
            $modelConfig,
            $this->messageRepository,
            $this->createStub(UserRepository::class),
            $this->createStub(RateLimitService::class),
            $this->em,
            new NullLogger(),
        );
    }

    /**
     * Same wiring as createService(), but records what was actually sent to the
     * model so the request shape itself can be asserted.
     *
     * @param array{messages: mixed, options: array<string, mixed>}|null $captured
     */
    private function createServiceCapturingTheRequest(?array &$captured): ChatTitleService
    {
        $service = $this->createService();

        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array|string $messages, ?int $userId, array $options) use (&$captured): array {
                $captured = ['messages' => $messages, 'options' => $options];

                return ['content' => 'Greeting', 'provider' => 'ollama', 'model' => 'llama3.2', 'usage' => []];
            }
        );

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getSummaryModelConfig')->willReturn(self::SUMMARY_CONFIG);

        return new ChatTitleService(
            $this->aiFacade,
            $modelConfig,
            $this->messageRepository,
            $this->createStub(UserRepository::class),
            $this->createStub(RateLimitService::class),
            $this->em,
            new NullLogger(),
        );
    }

    private function chat(?string $title = null): Chat
    {
        $chat = new Chat();
        $chat->setTitle($title);

        $reflection = new \ReflectionProperty(Chat::class, 'id');
        $reflection->setValue($chat, 7);

        return $chat;
    }

    private function message(string $direction, string $text): Message
    {
        $message = new Message();
        $message->setDirection($direction);
        $message->setText($text);

        return $message;
    }

    /** @param list<Message> $history */
    private function withHistory(array $history): void
    {
        $this->messageRepository->method('findChatHistory')->willReturn($history);
    }

    /** @return list<Message> */
    private function oneCompleteTurn(): array
    {
        return [
            $this->message('IN', 'Hi'),
            $this->message('OUT', 'Hello! How can I help you today?'),
        ];
    }

    public function testFirstCompleteTurnGetsAnAiTitle(): void
    {
        $service = $this->createService();
        $this->withHistory($this->oneCompleteTurn());
        $chat = $this->chat();

        $this->em->expects($this->once())->method('flush');

        $title = $service->titleWebChatIfNeeded($chat, 1);

        self::assertSame('Invoice import problem', $title);
        self::assertSame('Invoice import problem', $chat->getTitle());
    }

    /**
     * A lone user message is what produced the useless titles in the first
     * place — the answer usually carries the subject.
     */
    public function testAnIncompleteTurnIsNotTitledYet(): void
    {
        $service = $this->createService();
        $this->withHistory([$this->message('IN', 'Hi')]);

        $this->aiFacade->expects($this->never())->method('chat');

        self::assertNull($service->titleWebChatIfNeeded($this->chat(), 1));
    }

    public function testATitleTheUserChoseIsNeverReplaced(): void
    {
        $service = $this->createService();
        $chat = $this->chat('Quarterly planning');

        $this->messageRepository->expects($this->never())->method('findChatHistory');
        $this->aiFacade->expects($this->never())->method('chat');
        $this->em->expects($this->never())->method('flush');

        self::assertNull($service->titleWebChatIfNeeded($chat, 1));
        self::assertSame('Quarterly planning', $chat->getTitle());
    }

    /**
     * Chats created before this change, and via other channels, carry the
     * placeholder label rather than a null title.
     *
     * @param non-empty-string $placeholder
     */
    #[DataProvider('placeholderTitles')]
    public function testAPlaceholderTitleIsTreatedAsUntitled(string $placeholder): void
    {
        $service = $this->createService();
        $this->withHistory($this->oneCompleteTurn());
        $chat = $this->chat($placeholder);

        self::assertSame('Invoice import problem', $service->titleWebChatIfNeeded($chat, 1));
    }

    /** @return iterable<string, array{string}> */
    public static function placeholderTitles(): iterable
    {
        yield 'english' => ['New Chat'];
        yield 'german' => ['Neuer Chat'];
        yield 'blank' => ['   '];
    }

    /**
     * A title is cosmetic. If the model is unavailable the turn must still
     * finish, and the chat stays untitled so the sidebar falls back to the
     * first-message preview.
     */
    public function testAFailingModelLeavesTheChatUntitledWithoutThrowing(): void
    {
        $service = $this->createService(aiReply: null);
        $this->withHistory($this->oneCompleteTurn());
        $chat = $this->chat();

        self::assertNull($service->titleWebChatIfNeeded($chat, 1));
        self::assertNull($chat->getTitle());
    }

    public function testAnEmptyModelReplyIsNotStoredAsATitle(): void
    {
        $service = $this->createService(aiReply: "  \n ");
        $this->withHistory($this->oneCompleteTurn());
        $chat = $this->chat();

        self::assertNull($service->titleWebChatIfNeeded($chat, 1));
        self::assertNull($chat->getTitle());
    }

    /**
     * Models pad their answers. Whatever comes back has to end up as a bare
     * label, because it is rendered straight into the sidebar.
     */
    #[DataProvider('paddedReplies')]
    public function testModelPaddingIsStrippedFromTheTitle(string $reply, string $expected): void
    {
        $service = $this->createService($reply);
        $this->withHistory($this->oneCompleteTurn());

        self::assertSame($expected, $service->titleWebChatIfNeeded($this->chat(), 1));
    }

    /** @return iterable<string, array{string, string}> */
    public static function paddedReplies(): iterable
    {
        yield 'double quotes' => ['"Invoice import"', 'Invoice import'];
        yield 'single quotes' => ["'Invoice import'", 'Invoice import'];
        yield 'markdown bold' => ['**Invoice import**', 'Invoice import'];
        yield 'trailing period' => ['Invoice import.', 'Invoice import'];
        yield 'label prefix' => ['Title: Invoice import', 'Invoice import'];
        yield 'german label prefix' => ['Titel: Rechnungsimport', 'Rechnungsimport'];
        yield 'explanatory second line' => ["Invoice import\nThis title covers…", 'Invoice import'];
        yield 'collapsed whitespace' => ["Invoice    import\t", 'Invoice import'];
    }

    public function testAnOverlongTitleIsCutToLabelLength(): void
    {
        $service = $this->createService(str_repeat('a', 200));
        $this->withHistory($this->oneCompleteTurn());

        $title = $service->titleWebChatIfNeeded($this->chat(), 1);

        self::assertNotNull($title);
        self::assertSame(ChatTitleService::MAX_TITLE_LENGTH, mb_strlen($title));
    }

    /**
     * The prompt is the whole context of the request: a title must not cost a
     * full chat turn's tokens, and must not leak a system prompt or tools.
     */
    public function testThePromptIsASingleBareUserTurn(): void
    {
        $captured = null;
        $service = $this->createServiceCapturingTheRequest($captured);
        $this->withHistory($this->oneCompleteTurn());

        $service->titleWebChatIfNeeded($this->chat(), 1);

        self::assertIsArray($captured);
        self::assertCount(1, $captured['messages'], 'A title needs exactly one turn');
        self::assertSame('user', $captured['messages'][0]['role'], 'No system prompt is attached');
        self::assertStringContainsString('Hello! How can I help you today?', $captured['messages'][0]['content']);

        // The model must come from the SUMMARIZE capability, never a literal.
        self::assertSame('ollama', $captured['options']['provider']);
        self::assertSame('llama3.2', $captured['options']['model']);
    }

    public function testTurnsAreDerivedFromMessageDirection(): void
    {
        $service = $this->createService();

        $turns = $service->toTurns([
            $this->message('IN', 'How do I import invoices?'),
            $this->message('OUT', 'Open the Files page…'),
            $this->message('IN', '   '),
        ]);

        self::assertSame([
            ['role' => 'user', 'text' => 'How do I import invoices?'],
            ['role' => 'assistant', 'text' => 'Open the Files page…'],
        ], $turns, 'Blank messages contribute nothing and are dropped');
    }

    public function testGenerateWithoutTurnsMakesNoModelCall(): void
    {
        $service = $this->createService();

        $this->aiFacade->expects($this->never())->method('chat');

        self::assertNull($service->generate([], 1, 'CHAT_TITLE'));
    }
}
