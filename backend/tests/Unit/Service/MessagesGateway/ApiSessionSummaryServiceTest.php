<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MessagesGateway;

use App\AI\Service\AiFacade;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\MessagesGateway\ApiSessionSummaryService;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Unit tests for the debounced per-API-session summary fold.
 *
 * The contract under test:
 * - the FIRST request of a session refreshes immediately (chat surfaces right
 *   away in the chat list),
 * - subsequent requests inside the debounce window only buffer their excerpt
 *   (no AI call, no DB write),
 * - the Nth buffered request forces a refresh,
 * - a summarizer failure keeps the pending excerpts so the next command
 *   retries the fold.
 */
final class ApiSessionSummaryServiceTest extends TestCase
{
    private const USER_ID = 123;
    private const SESSION_KEY = 'session-abc';

    private ApiSessionSummaryService $service;
    private ArrayAdapter $cache;
    private AiFacade&MockObject $aiFacade;
    private RateLimitService&MockObject $rateLimitService;
    private EntityManagerInterface&MockObject $em;
    private EntityRepository&MockObject $messageRepository;
    private User $user;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $modelConfigService = $this->createMock(ModelConfigService::class);
        $modelConfigService->method('getSummaryModelConfig')->willReturn([
            'provider' => 'groq',
            'model' => 'test-summary-model',
            'model_id' => 42,
        ]);

        $this->user = new User();
        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->method('find')->willReturn($this->user);

        $chat = new Chat();
        $chat->setUserId(self::USER_ID);
        $chatRepository = $this->createMock(EntityRepository::class);
        $chatRepository->method('find')->willReturn($chat);

        $this->messageRepository = $this->createMock(EntityRepository::class);

        $this->em->method('getRepository')->willReturnCallback(
            fn (string $class) => match ($class) {
                User::class => $userRepository,
                Chat::class => $chatRepository,
                Message::class => $this->messageRepository,
                default => self::fail('Unexpected repository: '.$class),
            }
        );

        $this->service = new ApiSessionSummaryService(
            $this->aiFacade,
            $modelConfigService,
            $this->rateLimitService,
            $this->em,
            $this->cache,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );
    }

    public function testFirstRequestRefreshesImmediatelyAndMetersTheSummarizer(): void
    {
        $this->messageRepository->method('findOneBy')->willReturn(null);

        $this->aiFacade->expects($this->once())
            ->method('chat')
            ->willReturn([
                'content' => 'The session refactors the billing module.',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
                'provider' => 'groq',
                'model' => 'test-summary-model',
            ]);

        // The summarizer's own cost must land in BUSELOG under API_SUMMARY.
        $this->rateLimitService->expects($this->once())
            ->method('recordUsage')
            ->with(
                $this->isInstanceOf(User::class),
                'SORTING',
                $this->callback(fn (array $meta) => 'API_SUMMARY' === $meta['source']),
            );

        // Chat + IN turn + OUT turn + summary are created and flushed. Mimic
        // Doctrine's identity assignment — setMeta() needs the message to
        // have an ID post-flush.
        $this->em->expects($this->exactly(4))
            ->method('persist')
            ->willReturnCallback(fn (object $entity) => self::assignId($entity));
        $this->em->expects($this->atLeastOnce())->method('flush');

        $this->record('Fix the invoice rounding bug', 'Done, patched InvoiceCalculator.');

        $state = $this->readState();
        $this->assertSame('The session refactors the billing module.', $state['summary']);
        $this->assertSame([], $state['pending']);
        $this->assertSame(0, $state['countSinceRefresh']);
        $this->assertGreaterThan(0, $state['lastRefreshAt']);
    }

    public function testRequestInsideDebounceWindowOnlyBuffersExcerpt(): void
    {
        $this->seedState([
            'summary' => 'Existing summary.',
            'pending' => [],
            'countSinceRefresh' => 1,
            'lastRefreshAt' => time(), // refresh just happened
            'chatId' => 7,
        ]);

        $this->aiFacade->expects($this->never())->method('chat');
        $this->em->expects($this->never())->method('flush');

        $this->record('Second request', 'Second response');

        $state = $this->readState();
        $this->assertSame('Existing summary.', $state['summary']);
        $this->assertCount(1, $state['pending']);
        $this->assertStringContainsString('Second request', $state['pending'][0]);
        $this->assertSame(2, $state['countSinceRefresh']);
    }

    public function testNthBufferedRequestForcesRefresh(): void
    {
        $this->seedState([
            'summary' => 'Existing summary.',
            'pending' => ['Request: a\nResponse: b'],
            'countSinceRefresh' => 4, // this call makes 5 → REFRESH_EVERY_N_REQUESTS
            'lastRefreshAt' => time(),
            'chatId' => 7,
        ]);

        $existingMessage = new Message();
        $existingMessage->setText('old summary text');
        self::assignId($existingMessage);
        $this->messageRepository->method('findOneBy')->willReturn($existingMessage);

        $this->aiFacade->expects($this->once())
            ->method('chat')
            ->willReturn(['content' => 'Updated rolling summary.', 'usage' => []]);

        $this->record('Fifth request', 'Fifth response');

        // The single summary message is updated in place, not duplicated, and
        // labelled so it never reads as a message someone sent.
        $this->assertSame('[Session log] Updated rolling summary.', $existingMessage->getText());

        $state = $this->readState();
        $this->assertSame('Updated rolling summary.', $state['summary']);
        $this->assertSame([], $state['pending']);
        $this->assertSame(0, $state['countSinceRefresh']);
    }

    public function testSummarizerPromptPinsEnglishFromTheRequestExcerpt(): void
    {
        $this->messageRepository->method('findOneBy')->willReturn(null);
        $this->em->method('persist')->willReturnCallback(fn (object $entity) => self::assignId($entity));
        $this->em->method('flush');

        $this->aiFacade->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (array $messages): bool {
                $system = (string) ($messages[0]['content'] ?? '');

                return str_contains($system, 'Write the summary in English')
                    && str_contains($system, 'write your reply in English')
                    && str_contains($system, 'do not translate quoted');
            }))
            ->willReturn(['content' => 'Asked who the assistant is.', 'usage' => []]);

        $this->recordWithUserLanguage('de', 'who are you?', 'I am a helpful assistant.');
    }

    public function testSummarizerPromptPinsGermanWhenBitteIsTheRequestAnchor(): void
    {
        $this->messageRepository->method('findOneBy')->willReturn(null);
        $this->em->method('persist')->willReturnCallback(fn (object $entity) => self::assignId($entity));
        $this->em->method('flush');

        $this->aiFacade->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (array $messages): bool {
                $system = (string) ($messages[0]['content'] ?? '');

                return str_contains($system, 'Write the summary in German')
                    && str_contains($system, 'write your reply in German');
            }))
            ->willReturn(['content' => 'Der Nutzer bat um das Öffnen einer Datei.', 'usage' => []]);

        $this->recordWithUserLanguage('en', 'Bitte öffne die Datei', 'Opened.');
    }

    public function testSummarizerPromptFallsBackToAccountLocaleOnALanguageTie(): void
    {
        $this->messageRepository->method('findOneBy')->willReturn(null);
        $this->em->method('persist')->willReturnCallback(fn (object $entity) => self::assignId($entity));
        $this->em->method('flush');

        $this->aiFacade->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (array $messages): bool {
                $system = (string) ($messages[0]['content'] ?? '');

                return str_contains($system, 'Write the summary in Spanish')
                    && str_contains($system, 'write your reply in Spanish');
            }))
            ->willReturn(['content' => 'Resumen de la sesión.', 'usage' => []]);

        // qué (es) and is (en) score equally; do not pick English by array order.
        $this->recordWithUserLanguage('es', 'qué is', 'ok');
    }

    public function testSummarizerPromptFallsBackToAccountLocaleWhenExcerptHasNoSignal(): void
    {
        $this->messageRepository->method('findOneBy')->willReturn(null);
        $this->em->method('persist')->willReturnCallback(fn (object $entity) => self::assignId($entity));
        $this->em->method('flush');

        $this->aiFacade->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (array $messages): bool {
                $system = (string) ($messages[0]['content'] ?? '');

                return str_contains($system, 'Write the summary in Spanish')
                    && str_contains($system, 'write your reply in Spanish');
            }))
            ->willReturn(['content' => 'Resumen de la sesión.', 'usage' => []]);

        $this->recordWithUserLanguage('es', 'ok', 'done');
    }

    /**
     * The thread must show what the external client saw: the real user turn,
     * the real assistant turn, and the rolling summary as a labelled log —
     * never the recap alone (#2023).
     */
    public function testRefreshPersistsTurnsAndLabelsTheSummary(): void
    {
        // The summary lookup must stay on its own topic now that assistant
        // turns share direction OUT.
        $this->messageRepository->expects($this->once())
            ->method('findOneBy')
            ->with($this->callback(static fn (array $criteria): bool => 'OUT' === ($criteria['direction'] ?? null)
                && 'api_session' === ($criteria['topic'] ?? null)))
            ->willReturn(null);

        $this->aiFacade->method('chat')->willReturn(['content' => 'Greeting exchanged.', 'usage' => []]);

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(
            function (object $entity) use (&$persisted): void {
                self::assignId($entity);
                $persisted[] = $entity;
            }
        );
        $this->em->method('flush');

        $this->record('hi', 'Hallo! Wie kann ich dir heute helfen?');

        $messages = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof Message));
        self::assertCount(3, $messages);

        [$userTurn, $assistantTurn, $summary] = $messages;

        self::assertSame('IN', $userTurn->getDirection());
        self::assertSame('CHAT', $userTurn->getTopic());
        self::assertSame('hi', $userTurn->getText());
        self::assertSame('complete', $userTurn->getStatus());

        self::assertSame('OUT', $assistantTurn->getDirection());
        self::assertSame('CHAT', $assistantTurn->getTopic());
        self::assertSame('Hallo! Wie kann ich dir heute helfen?', $assistantTurn->getText());
        self::assertSame('complete', $assistantTurn->getStatus());

        self::assertSame('OUT', $summary->getDirection());
        self::assertSame('api_session', $summary->getTopic());
        self::assertSame('[Session log] Greeting exchanged.', $summary->getText());

        self::assertGreaterThan($userTurn->getUnixTimestamp(), $assistantTurn->getUnixTimestamp());
        self::assertGreaterThan(
            $assistantTurn->getUnixTimestamp(),
            $summary->getUnixTimestamp(),
            'the summary lands after the turns so list previews keep showing the trail'
        );
    }

    public function testSummarizerFailureKeepsPendingExcerptsForRetry(): void
    {
        $this->aiFacade->expects($this->once())
            ->method('chat')
            ->willThrowException(new \RuntimeException('provider down'));

        $this->em->expects($this->never())->method('flush');
        $this->rateLimitService->expects($this->never())->method('recordUsage');

        $this->record('Request text', 'Response text');

        $state = $this->readState();
        $this->assertSame('', $state['summary']);
        $this->assertCount(1, $state['pending']);
        $this->assertSame(0, $state['lastRefreshAt'], 'a failed fold must stay due for retry');
    }

    /**
     * Assign a database identity in-memory, like Doctrine does on flush.
     */
    private static function assignId(object $entity, int $id = 999): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setValue($entity, $id);
    }

    private function record(string $requestExcerpt, string $responseExcerpt): void
    {
        $this->service->record(
            self::USER_ID,
            self::SESSION_KEY,
            'claude-code',
            'claude-sonnet-4-20250514',
            $requestExcerpt,
            $responseExcerpt,
        );
    }

    private function recordWithUserLanguage(string $language, string $requestExcerpt, string $responseExcerpt): void
    {
        $this->user->setUserDetails(['language' => $language]);
        $this->record($requestExcerpt, $responseExcerpt);
    }

    private function stateKey(): string
    {
        return 'api_session_summary.'.hash('sha256', self::USER_ID.'|'.self::SESSION_KEY);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function seedState(array $state): void
    {
        $item = $this->cache->getItem($this->stateKey());
        $item->set($state);
        $this->cache->save($item);
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        $item = $this->cache->getItem($this->stateKey());
        $this->assertTrue($item->isHit(), 'expected session state in cache');

        $state = $item->get();
        $this->assertIsArray($state);

        return $state;
    }
}
