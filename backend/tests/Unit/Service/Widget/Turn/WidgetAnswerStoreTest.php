<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget\Turn;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Media\GeneratedFileMetadataNormalizer;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use App\Service\Widget\Turn\StoredVisitorMessage;
use App\Service\Widget\Turn\WidgetAnswerStore;
use App\Service\Widget\Turn\WidgetTurn;
use App\Service\Widget\WidgetRealtimeBroadcaster;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class WidgetAnswerStoreTest extends TestCase
{
    private ?Message $answer = null;
    /** @var list<mixed> */
    private array $usage = [];
    /** @var list<array<string, mixed>> */
    private array $events = [];
    private WidgetSession $session;

    protected function setUp(): void
    {
        $this->session = (new WidgetSession())->setWidgetId('wdg_1')->setSessionId('sess');
    }

    public function testTheVisitorsMessageTakesTheTopicAndALanguageCode(): void
    {
        $stored = $this->stored();
        $this->store()->store($this->turn(), $stored, 'Answer', ['classification' => ['topic' => str_repeat('t', 70), 'language' => 'auto']]);

        self::assertSame([str_repeat('t', 64), 'en', 'complete'], [$stored->message->getTopic(), $stored->message->getLanguage(), $stored->message->getStatus()]);
        self::assertSame([str_repeat('t', 64), 'en'], [$this->answer()->getTopic(), $this->answer()->getLanguage()]);
    }

    public function testTheAnswerKeepsItsSourcesAndThePreviewLeavesTheScratchpadOut(): void
    {
        $this->store()->store($this->turn(), $this->stored(), '<think>Hm</think>Answer', ['response' => ['metadata' => ['provider' => 'test', 'rag_sources' => [['n' => 1, 'fileName' => 'faq.md']]]]]);

        self::assertSame('[{"n":1,"fileName":"faq.md"}]', $this->answer()->getMeta('rag_sources'));
        self::assertSame(['test', 'WIDGET', '<think>Hm</think>Answer'], [$this->answer()->getProviderIndex(), $this->answer()->getTopic(), $this->answer()->getText()]);
        self::assertSame('Answer', $this->session->getLastMessagePreview());
        self::assertSame([['direction' => 'OUT', 'text' => 'Answer', 'sender' => 'ai']], $this->events);
    }

    public function testGeneratedMediaIsAttachedToTheAnswer(): void
    {
        $this->store(new File())->store($this->turn(), $this->stored(), 'Here', ['response' => ['metadata' => ['file' => ['path' => 'generated/cat.png', 'type' => 'png'], 'local_path' => 'generated/cat.png']]]);

        self::assertSame([1, 'generated/cat.png', 'png', 1], [$this->answer()->getFile(), $this->answer()->getFilePath(), $this->answer()->getFileType(), count($this->answer()->getFiles())]);
    }

    public function testTheOwnersUsageIsRecordedForTheWidgetChannel(): void
    {
        $this->store()->store($this->turn(), $this->stored([4]), 'Answer', ['response' => ['metadata' => ['provider' => 'test', 'model' => 'm', 'model_id' => 12, 'usage' => ['total' => 9]]]]);

        self::assertSame([['MESSAGES', ['provider' => 'test', 'model' => 'm', 'model_id' => 12, 'usage' => ['total' => 9], 'channel' => 'WIDGET', 'files' => [4], 'response_text' => 'Answer', 'input_text' => 'Hi']]], $this->usage);
    }

    public function testOnlyCitedSourcesCount(): void
    {
        self::assertSame([['n' => 1]], WidgetAnswerStore::ragSources(['rag_sources' => [['n' => 1]]]));
        self::assertNull(WidgetAnswerStore::ragSources(['rag_sources' => []]));
        self::assertNull(WidgetAnswerStore::ragSources([]));
    }

    private function answer(): Message
    {
        self::assertNotNull($this->answer, 'No answer was stored.');

        return $this->answer;
    }

    private function store(?File $generated = null): WidgetAnswerStore
    {
        $sessions = $this->createStub(WidgetSessionService::class);
        $sessions->method('getOrCreateSession')->willReturn($this->session);
        $broadcaster = $this->createStub(WidgetRealtimeBroadcaster::class);
        $broadcaster->method('publishSessionEvent')->willReturnCallback(function (string $widgetId, string $sessionId, string $type, array $payload): void {
            $this->events[] = ['direction' => $payload['direction'], 'text' => $payload['text'], 'sender' => $payload['sender']];
        });
        $files = $this->createStub(GeneratedFileRegistrar::class);
        $files->method('register')->willReturn($generated);
        $rateLimits = $this->createStub(RateLimitService::class);
        $rateLimits->method('recordUsage')->willReturnCallback(function (User $user, string $action, array $metadata): RecordedUsage {
            $this->usage[] = [$action, $metadata];

            return new RecordedUsage('0', '0', 0, 0, 0);
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->answer = $entity instanceof Message ? $entity : $this->answer;
        });

        return new WidgetAnswerStore($sessions, $broadcaster, new GeneratedFileMetadataNormalizer(), $files, $this->createStub(ConversationSummaryRefreshDispatcher::class), $rateLimits, $em);
    }

    private function turn(): WidgetTurn
    {
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(7);

        return new WidgetTurn((new Widget())->setWidgetId('wdg_1'), $owner, $this->session, [], 'Hi', [], null, '', false);
    }

    /**
     * @param list<int> $fileIds
     */
    private function stored(array $fileIds = []): StoredVisitorMessage
    {
        return new StoredVisitorMessage(new Chat(), (new Message())->setText('Hi')->setTrackingId(1), $fileIds);
    }
}
