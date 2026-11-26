<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\Chat\ChatTitleService;
use App\Service\Widget\InvalidWidgetSessionId;
use App\Service\Widget\WidgetExportService;
use App\Service\Widget\WidgetSessionId;
use App\Service\Widget\WidgetSessionService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A visitor picks the session id, the owner opens it in a spreadsheet.
 */
final class WidgetSessionIdTest extends TestCase
{
    private const FORMULA = '=HYPERLINK("https://evil.test","open")';

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function ids(): iterable
    {
        yield 'what the widget sends' => ['sess_3f2b8c1e-9a4d-4e7b-8c2a-1d5e6f7a8b9c', true];
        yield 'an owner test session' => ['test_sess_3f2b8c1e-9a4d-4e7b-8c2a-1d5e6f7a8b9c', true];
        yield 'short' => ['sess', true];
        yield 'longest the column holds' => [str_repeat('a', 64), true];
        yield 'too long' => [str_repeat('a', 65), false];
        yield 'empty' => ['', false];
        yield 'formula' => [self::FORMULA, false];
        yield 'plus' => ['+1', false];
        yield 'at' => ['@SUM(A1)', false];
        yield 'markup' => ['<img src=x>', false];
        yield 'space' => ['sess 1', false];
        yield 'trailing newline' => ["sess\n", false];
    }

    #[DataProvider('ids')]
    public function testOnlyTheShapeTheWidgetSendsIsAnId(string $id, bool $valid): void
    {
        self::assertSame($valid, WidgetSessionId::isValid($id));
    }

    public function testANewSessionIsNotCreatedUnderAForeignShape(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $this->expectException(InvalidWidgetSessionId::class);

        $this->sessions($em, null)->getOrCreateSession('wdg_1', self::FORMULA);
    }

    public function testASessionStoredByAnEarlierReleaseStaysReachable(): void
    {
        $stored = (new WidgetSession())->setWidgetId('wdg_1')->setSessionId('visitor@old.example');
        $stored->setExpires(time() + 3600);

        self::assertSame(
            $stored,
            $this->sessions($this->createStub(EntityManagerInterface::class), $stored)->getOrCreateSession('wdg_1', 'visitor@old.example'),
        );
    }

    public function testTheCsvExportDoesNotStartACellWithAStoredFormula(): void
    {
        $session = (new WidgetSession())->setWidgetId('wdg_1')->setSessionId(self::FORMULA);
        $session->setChatId(5);

        $sessionRepository = $this->createStub(WidgetSessionRepository::class);
        $sessionRepository->method('findSessionsByWidget')->willReturn(['sessions' => [$session], 'total' => 1]);

        $chat = $this->createStub(Chat::class);
        $chat->method('getId')->willReturn(5);
        $chat->method('getUserId')->willReturn(7);
        $chats = $this->createStub(ChatRepository::class);
        $chats->method('find')->willReturn($chat);

        $message = $this->createStub(Message::class);
        $message->method('getDirection')->willReturn('IN');
        $message->method('getText')->willReturn('Hello');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFiles')->willReturn(new ArrayCollection());
        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findChatHistory')->willReturn([$message]);

        $widget = $this->createStub(Widget::class);
        $widget->method('getWidgetId')->willReturn('wdg_1');
        $widget->method('getConfig')->willReturn([]);

        $file = (new WidgetExportService($sessionRepository, $chats, $messages))->exportToCsv($widget);
        try {
            $handle = fopen($file, 'r');
            self::assertNotFalse($handle);
            fgetcsv($handle, null, ',', '"', '\\');
            $row = fgetcsv($handle, null, ',', '"', '\\');
            fclose($handle);
        } finally {
            unlink($file);
        }

        self::assertIsArray($row);
        self::assertSame("'".self::FORMULA, $row[0]);
    }

    private function sessions(EntityManagerInterface $em, ?WidgetSession $stored): WidgetSessionService
    {
        $repository = $this->createStub(WidgetSessionRepository::class);
        $repository->method('findByWidgetAndSession')->willReturn($stored);

        return new WidgetSessionService(
            $em,
            $repository,
            $this->createStub(MessageRepository::class),
            $this->createStub(ChatTitleService::class),
            new NullLogger(),
        );
    }
}
