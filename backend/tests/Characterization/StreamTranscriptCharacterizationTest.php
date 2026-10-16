<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Controller\StreamController;
use App\Entity\Chat;
use App\Entity\GuestSession;
use App\Entity\Message;
use App\Entity\UseLog;
use App\Entity\User;
use App\Service\Chat\GuestChatConfig;
use App\Service\Chat\GuestSessionService;
use App\Service\Message\MessageProcessor;
use App\Tests\Characterization\Support\CleanupSnapshot;
use App\Tests\Characterization\Support\PayloadShape;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Golden SSE transcript of the chat stream.
 *
 * The wire format is `data: {"status": "<name>", ...}`. This locks the
 * ordered status names and the JSON key/type shape for five requests:
 * plain incognito chat, the same turn persisted on a real chat (so `run_started`
 * and a numeric `messageId` are part of the contract), a turn that forwards
 * the RAG / memory / web-search / URL-fetch statuses, a provider error, a
 * cancellation, and a guest who has used the trial. MessageProcessor is scripted so the transcript does not depend on a
 * live model; StreamController is the real one, so a split that changes the
 * frames fails here.
 *
 * `usage_totals` follows the taximeter switch in the database and is left out
 * of the shape. Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 */
#[AllowMockObjectsWithoutExpectations]
final class StreamTranscriptCharacterizationTest extends WebTestCase
{
    private const SNAPSHOT = __DIR__.'/__snapshots__/sse_transcript.json';

    /** @var list<string> */
    private const KNOWLEDGE_STATUSES = [
        'started', 'preprocessing', 'classifying', 'classified',
        'searching', 'search_complete', 'searching_files',
        'fetching_urls', 'urls_fetched', 'pages_read',
        'checking_memories', 'memories_loaded', 'docs_loaded',
        'digests_loaded', 'feedback_loaded', 'generating', 'generated',
    ];

    public function testTranscriptShapesMatchTheSnapshot(): void
    {
        $user = $this->fixtureUser();
        $this->clearQuota($user);

        $actual = [];
        foreach ($this->scenarios() as $id => $scenario) {
            $actual[$id] = $this->transcribe($user, $scenario);
        }

        $knowledge = array_column($actual['knowledge'], 'status');
        foreach (self::KNOWLEDGE_STATUSES as $status) {
            self::assertContains($status, $knowledge, 'The knowledge transcript dropped a pipeline status.');
        }
        self::assertContains('file', $knowledge);
        self::assertContains('links', $knowledge);
        self::assertContains('data', $knowledge);
        self::assertContains('complete', $knowledge);
        self::assertSame('guest_limit_reached', $actual['guest_limit'][0]['status'] ?? null);
        self::assertNotContains('complete', array_column($actual['cancelled'], 'status'));
        self::assertContains('run_started', array_column($actual['persisted_chat'], 'status'));
        self::assertNotContains('run_started', array_column($actual['plain_chat'], 'status'));
        self::assertSame('int', $this->completeField($actual['persisted_chat'], 'messageId'));
        self::assertSame('null', $this->completeField($actual['plain_chat'], 'messageId'));

        CleanupSnapshot::assertSame(self::SNAPSHOT, $actual, 'SSE transcript');
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return list<array{status: mixed, shape: array<string, mixed>|string}>
     */
    private function transcribe(User $user, array $scenario): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        if ('guest' !== $scenario['kind']) {
            $client->loginUser($user);
        }

        // The doubles replace container services before the controller is
        // built, so the transcript runs through the real stream wiring.
        if ('guest' === $scenario['kind']) {
            $this->installGuestLimit();
        } else {
            static::getContainer()->set(MessageProcessor::class, $this->scriptedProcessor($scenario));
        }

        /** @var StreamController $controller */
        $controller = $client->getContainer()->get(StreamController::class);
        $flag = new \ReflectionProperty(StreamController::class, 'sseCaptureMode');
        $flag->setValue($controller, true);

        $chatId = null;
        try {
            if ('guest' === $scenario['kind']) {
                $request = Request::create('/api/v1/messages/stream', 'GET', [
                    'guestSession' => 'guest-limit',
                    'message' => 'Hello',
                ]);
                $actor = null;
            } else {
                if ('persisted' === $scenario['kind']) {
                    $chatId = $this->openChat($client, $user);
                }
                $body = [
                    'message' => 'Hello, how are you today?',
                    'trackId' => 4242,
                    'language' => 'en',
                ];
                if (null === $chatId) {
                    $body['incognito'] = true;
                } else {
                    $body['chatId'] = $chatId;
                }
                $request = Request::create(
                    '/api/v1/messages/stream',
                    'POST',
                    [],
                    [],
                    [],
                    ['CONTENT_TYPE' => 'application/json'],
                    json_encode($body, JSON_THROW_ON_ERROR),
                );
                $actor = $user;
            }

            $response = $controller->streamMessage($request, $actor);
            self::assertInstanceOf(StreamedResponse::class, $response);

            ob_start();
            try {
                $response->sendContent();
            } finally {
                $raw = (string) ob_get_clean();
            }

            return $this->shaped($this->frames($raw));
        } finally {
            if (null !== $chatId) {
                $this->forgetChat($chatId);
            }
        }
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function scriptedProcessor(array $scenario): MessageProcessor
    {
        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('processStream')->willReturnCallback(
            function (Message $message, callable $stream, ?callable $status = null, array $options = []) use ($scenario): array {
                foreach ($scenario['statuses'] as $name) {
                    if (null !== $status) {
                        $status([
                            'status' => $name,
                            'message' => $name,
                            'metadata' => ['stage' => $name],
                            'timestamp' => 1,
                        ]);
                    }
                }
                foreach ($scenario['chunks'] as $chunk) {
                    $stream($chunk);
                }

                return $scenario['result'];
            },
        );

        return $processor;
    }

    private function installGuestLimit(): void
    {
        $config = $this->createMock(GuestChatConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $session = (new GuestSession())->setSessionId('guest-limit');
        $sessions = $this->createMock(GuestSessionService::class);
        $sessions->method('getSession')->willReturn($session);
        $sessions->method('checkLimit')->willReturn(false);

        static::getContainer()->set(GuestChatConfig::class, $config);
        static::getContainer()->set(GuestSessionService::class, $sessions);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function scenarios(): array
    {
        $success = [
            'success' => true,
            'classification' => ['topic' => 'general', 'language' => 'en', 'intent' => 'chat'],
            'response' => [
                'content' => 'Hello.',
                'metadata' => ['provider' => 'test', 'model' => 'test-model'],
            ],
        ];

        return [
            'plain_chat' => [
                'kind' => 'stream',
                'statuses' => ['started', 'preprocessing', 'classifying', 'classified', 'generating'],
                'chunks' => ['Hello.'],
                'result' => $success,
            ],
            'persisted_chat' => [
                'kind' => 'persisted',
                'statuses' => ['started', 'preprocessing', 'classifying', 'classified', 'generating'],
                'chunks' => ['Hello.'],
                'result' => $success,
            ],
            'knowledge' => [
                'kind' => 'stream',
                'statuses' => self::KNOWLEDGE_STATUSES,
                'chunks' => ['Noted.'],
                'result' => $this->knowledgeResult(),
            ],
            'provider_error' => [
                'kind' => 'stream',
                'statuses' => ['started', 'generating'],
                'chunks' => [],
                'result' => [
                    'success' => false,
                    'error' => 'AI provider failed for streaming',
                    'provider' => 'test',
                    'classification' => ['topic' => 'general', 'language' => 'en'],
                ],
            ],
            'cancelled' => [
                'kind' => 'stream',
                'statuses' => ['generating'],
                'chunks' => ['partial'],
                'result' => [
                    'success' => false,
                    'cancelled' => true,
                    'error' => 'Stream cancelled by user',
                ],
            ],
            'guest_limit' => ['kind' => 'guest'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function knowledgeResult(): array
    {
        return [
            'success' => true,
            'classification' => ['topic' => 'general', 'language' => 'en', 'intent' => 'chat'],
            'search_results' => [
                'query' => 'office move',
                'pages_read' => 1,
                'results' => [[
                    'title' => 'Move notes',
                    'url' => 'https://example.com/notes',
                    'description' => 'July',
                    'age' => '1d',
                    'fetched' => true,
                    'final_url' => 'https://example.com/notes',
                ]],
            ],
            'response' => [
                'content' => 'Noted.',
                'metadata' => [
                    'provider' => 'test',
                    'model' => 'test-model',
                    'file' => ['path' => 'notes/move.md', 'type' => 'md'],
                    'links' => [['title' => 'Notes', 'url' => 'https://example.com/notes']],
                    'memories' => [['id' => 42]],
                    'docs' => [['slug' => 'chat', 'title' => 'Chat']],
                    'rag_sources' => [[
                        'n' => 1,
                        'chunkId' => 'c1',
                        'fileId' => 3,
                        'fileName' => 'move.md',
                        'groupKey' => 'notes',
                        'score' => 0.8,
                        'startLine' => 1,
                        'endLine' => 2,
                    ]],
                ],
            ],
        ];
    }

    private function fixtureUser(): User
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['mail' => 'demo@synaplan.com']);
        self::ensureKernelShutdown();
        if (!$user instanceof User) {
            self::markTestSkipped('Fixture user demo@synaplan.com not found. Run fixtures first.');
        }

        return $user;
    }

    private function clearQuota(User $user): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(UseLog::class)->findBy(['userId' => $user->getId()]) as $log) {
            $em->remove($log);
        }
        $em->flush();
        self::ensureKernelShutdown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function frames(string $raw): array
    {
        $frames = [];
        foreach (preg_split("/\n\n+/", trim($raw)) ?: [] as $frame) {
            $frame = trim($frame);
            if (!str_starts_with($frame, 'data: ')) {
                continue;
            }
            $decoded = json_decode(substr($frame, 6), true);
            if (is_array($decoded)) {
                $frames[] = $decoded;
            }
        }

        return $frames;
    }

    /**
     * @param list<array<string, mixed>> $frames
     *
     * @return list<array{status: mixed, shape: array<string, mixed>|string}>
     */
    private function shaped(array $frames): array
    {
        $shaped = [];
        foreach ($frames as $frame) {
            unset($frame['usage_totals']);
            if (array_key_exists('total_ms', $frame)) {
                // round() is a float that JSON encodes as an int when it is
                // whole, so the type would flap between runs.
                $frame['total_ms'] = (float) $frame['total_ms'];
            }
            $shaped[] = [
                'status' => $frame['status'] ?? null,
                'shape' => PayloadShape::of($frame, true),
            ];
        }

        return $shaped;
    }

    private function openChat(KernelBrowser $client, User $user): int
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $chat = (new Chat())
            ->setUserId((int) $user->getId())
            ->setTitle('cleanup-v2 characterization');
        $em->persist($chat);
        $em->flush();
        $id = $chat->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function forgetChat(int $chatId): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Message::class)->findBy(['chatId' => $chatId]) as $message) {
            $em->remove($message);
        }
        $em->flush();
        $chat = $em->find(Chat::class, $chatId);
        if (null !== $chat) {
            $em->remove($chat);
            $em->flush();
        }
        self::ensureKernelShutdown();
    }

    /**
     * @param list<array{status: mixed, shape: array<string, mixed>|string}> $events
     */
    private function completeField(array $events, string $field): string
    {
        foreach ($events as $event) {
            if ('complete' !== $event['status'] || !is_array($event['shape'])) {
                continue;
            }
            $value = $event['shape'][$field] ?? null;
            self::assertIsString($value);

            return $value;
        }

        self::fail('The transcript has no complete frame.');
    }
}
