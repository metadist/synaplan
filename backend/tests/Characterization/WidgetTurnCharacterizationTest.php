<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Chat\Run\ChatRunService;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Message\MessageProcessor;
use App\Service\Notification\DiscordNotificationService;
use App\Service\Notification\SlackNotificationService;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use App\Service\Widget\WidgetRealtimeBroadcaster;
use App\Service\Widget\WidgetSessionService;
use App\Tests\Characterization\Support\CleanupSnapshot;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Golden record of the widget visitor turn, POST /api/v1/widget/{id}/message.
 *
 * Every way the turn's output leaves is recorded: the gate's refusals, what
 * reaches the message processor, the stored messages and session, the SSE
 * frames, the realtime events, the Slack and Discord pings, the summary
 * refresh, the usage record, the generated media, the run a reloading visitor
 * re-attaches to, and the number of flushes. The processor is scripted and every outbound service
 * is a double, so no request leaves the process; routing, the controller and
 * the services behind it are real. A client gone mid-stream is covered by
 * what every streamed turn records: the turn runs detached from the
 * connection and its replay log carries every frame.
 *
 * Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 */
#[AllowMockObjectsWithoutExpectations]
final class WidgetTurnCharacterizationTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const SNAPSHOT = __DIR__.'/__snapshots__/widget_turn.json';

    /** @var array{processor: list<mixed>, realtime: list<mixed>, slack: list<mixed>, discord: list<mixed>, summaries: int, usage: list<mixed>, generated: list<mixed>, http: list<string>} */
    private array $log = ['processor' => [], 'realtime' => [], 'slack' => [], 'discord' => [], 'summaries' => 0, 'usage' => [], 'generated' => [], 'http' => []];

    /** @var array<string, array<string, string>> */
    private array $placeholders = [];

    public function testEveryWidgetTurnMatchesTheSnapshot(): void
    {
        $actual = [];
        foreach (self::cases() as $id => $case) {
            $actual[$id] = $this->turn($id, $case);
            self::assertSame([], $this->log['http'], $id.': a request left the process');
        }

        self::assertSame(['data', 'complete'], array_values(array_intersect(['data', 'complete'], array_column($actual['answer']['requests'][0]['frames'], 'status'))));
        self::assertSame([], $actual['human_takeover']['processor'], 'A human takeover reached the AI.');

        CleanupSnapshot::assertSame(self::SNAPSHOT, $actual, 'widget turn');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function cases(): array
    {
        return [
            'answer' => ['headers' => ['HTTP_CF_IPCOUNTRY' => 'DE'], 'script' => self::answer(['Hello', ' there.'])],
            'answer_with_reasoning_cut_at_length' => ['script' => self::answer([
                ['type' => 'reasoning', 'content' => 'Weighing it.'],
                ['type' => 'content', 'content' => 'Short answer'],
                ['type' => 'finish', 'finish_reason' => 'length'],
            ])],
            'answer_as_json' => ['script' => self::answer(["{\"BTEXT\": \"From JSON\", \"BFILE\": \n", '}'])],
            'generated_image' => ['script' => self::answer(['Here is your image.'], ['file' => ['path' => 'generated/cat.png', 'type' => 'png'], 'local_path' => 'generated/cat.png'])],
            'invalid_json' => ['raw' => 'not json'],
            'missing_text' => ['body' => ['text' => '']],
            'unknown_widget' => ['widgetId' => 'wdg_char_missing'],
            'inactive_widget' => ['widget' => ['status' => 'inactive']],
            'owner_limit' => ['ownerAllowed' => false],
            'foreign_domain' => ['config' => ['allowedDomains' => ['shop.example.com']], 'headers' => ['HTTP_ORIGIN' => 'https://elsewhere.test']],
            'allowed_domain' => ['config' => ['allowedDomains' => ['*.example.com']], 'headers' => ['HTTP_ORIGIN' => 'https://shop.example.com']],
            'session_limit' => ['config' => ['messageLimit' => 1], 'texts' => ['First question', 'Second question']],
            'human_takeover' => ['session' => ['mode' => WidgetSession::MODE_HUMAN]],
            'auto_handoff' => [
                'config' => ['slackWebhookUrl' => 'https://hooks.slack.com/services/T0/B0/char', 'humanHandoffTriggers' => ['talk to a human']],
                'texts' => ['Please let me talk to a human'],
            ],
            'attached_file' => ['file' => true],
            'first_message_with_welcome' => ['config' => ['autoMessage' => 'Welcome! How can I help?']],
            'internal_owner_session' => [
                'signedIn' => true,
                'headers' => ['HTTP_X_WIDGET_INTERNAL_MODE' => 'true'],
                'config' => ['messageLimit' => 1],
                'texts' => ['First question', 'Second question'],
            ],
            'processor_error' => ['script' => [
                'statuses' => ['started', 'generating'],
                'chunks' => [],
                'result' => ['success' => false, 'error' => 'AI provider failed for streaming', 'provider' => 'test', 'classification' => ['topic' => 'general', 'language' => 'de']],
            ]],
            'processor_exception' => ['script' => ['statuses' => ['started'], 'chunks' => ['partial'], 'throw' => true]],
        ];
    }

    /**
     * @param list<string|array<string, mixed>> $chunks
     * @param array<string, mixed>              $metadata added to the response metadata
     *
     * @return array<string, mixed>
     */
    private static function answer(array $chunks, array $metadata = []): array
    {
        return [
            'statuses' => ['started', 'classified', 'generating'],
            'chunks' => $chunks,
            'result' => [
                'success' => true,
                'classification' => ['topic' => 'tools:widget-default', 'language' => 'auto', 'source' => 'widget'],
                'response' => [
                    'content' => 'ignored',
                    'metadata' => $metadata + [
                        'provider' => 'test',
                        'model' => 'test-model',
                        'model_id' => 12,
                        'tokens' => ['prompt' => 3, 'completion' => 4],
                        'rag_sources' => [['n' => 1, 'chunkId' => 'c1', 'fileName' => 'faq.md', 'score' => 0.8]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed>
     */
    private function turn(string $id, array $case): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->disableReboot();
        $this->log = ['processor' => [], 'realtime' => [], 'slack' => [], 'discord' => [], 'summaries' => 0, 'usage' => [], 'generated' => [], 'http' => []];
        $this->placeholders = [];
        $this->installDoubles($case);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->owner($em, $id);
        $widget = $this->widget($em, $owner, $id, $case);
        $sessionId = 'sess_char_'.$id;
        $fileIds = $this->arrange($em, $widget, $owner, $sessionId, $case);
        if ($case['signedIn'] ?? false) {
            $this->authenticateClient($client, $owner);
        }

        $flushes = new class {
            public int $count = 0;

            public function postFlush(): void
            {
                ++$this->count;
            }
        };
        $em->getEventManager()->addEventListener([Events::postFlush], $flushes);

        $requests = [];
        foreach ($case['texts'] ?? ['Hello, I need help'] as $text) {
            ignore_user_abort(false);
            $body = ($case['body'] ?? []) + ['sessionId' => $sessionId, 'text' => $text, 'files' => $fileIds];
            $client->request(
                'POST',
                '/api/v1/widget/'.($case['widgetId'] ?? $widget->getWidgetId()).'/message',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'] + ($case['headers'] ?? []),
                $case['raw'] ?? json_encode($body, JSON_THROW_ON_ERROR),
            );
            $requests[] = $this->response($client);
        }
        $em->getEventManager()->removeEventListener([Events::postFlush], $flushes);
        $em->clear();

        return [
            'requests' => $requests,
            'processor' => $this->log['processor'],
            'stored' => $this->stored($em, $widget->getWidgetId(), $sessionId),
            'realtime' => $this->normalize($this->log['realtime']),
            'slack' => $this->log['slack'],
            'discord' => $this->log['discord'],
            'summary_refreshes' => $this->log['summaries'],
            'usage' => $this->normalize($this->log['usage']),
            'generated_files' => $this->log['generated'],
            'flushes' => $flushes->count,
        ];
    }

    /**
     * @param array<string, mixed> $case
     */
    private function installDoubles(array $case): void
    {
        $container = static::getContainer();
        $script = $case['script'] ?? self::answer(['Hello', ' there.']);

        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('processStream')->willReturnCallback(
            function (Message $message, callable $stream, ?callable $status = null, array $options = []) use ($script): array {
                ksort($options);
                $this->log['processor'][] = [
                    'text' => $message->getText(),
                    'incoming' => ['status' => $message->getStatus(), 'files' => count($message->getFiles()), 'messageType' => $message->getMessageType()],
                    'detached_from_the_client' => 1 === ignore_user_abort(),
                    'options' => $options,
                ];
                foreach ($script['statuses'] as $name) {
                    if (null !== $status) {
                        $status(['status' => $name, 'message' => 'Step '.$name, 'metadata' => ['stage' => $name]]);
                    }
                }
                foreach ($script['chunks'] as $chunk) {
                    $stream($chunk);
                }
                if ($script['throw'] ?? false) {
                    throw new \RuntimeException('The processor failed mid-stream.');
                }

                return $script['result'];
            },
        );
        $container->set(MessageProcessor::class, $processor);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => $case['ownerAllowed'] ?? true]);
        $rateLimits->method('recordUsage')->willReturnCallback(function (User $user, string $action, array $metadata = []): RecordedUsage {
            ksort($metadata);
            $this->log['usage'][] = ['action' => $action, 'metadata' => $metadata];

            return new RecordedUsage('0.000000', '0.000000', 0, 0, 0);
        });
        $container->set(RateLimitService::class, $rateLimits);

        $broadcaster = $this->createMock(WidgetRealtimeBroadcaster::class);
        $broadcaster->method('publishSessionEvent')->willReturnCallback(function (string $widgetId, string $sessionId, string $type, array $payload): void {
            $this->log['realtime'][] = ['channel' => 'session', 'type' => $type, 'payload' => $payload];
        });
        $broadcaster->method('publishOperatorNotification')->willReturnCallback(function (string $widgetId, array $payload): void {
            $this->log['realtime'][] = ['channel' => 'operators', 'payload' => $payload];
        });
        $container->set(WidgetRealtimeBroadcaster::class, $broadcaster);

        $slack = $this->createMock(SlackNotificationService::class);
        $slack->method('notifyHumanHandoff')->willReturnCallback(
            function (Widget $widget, WidgetSession $session, string $webhookUrl, string $takeoverUrl, ?string $lastUserMessage = null, ?string $triggerReason = null, array $customFieldValues = []): bool {
                $this->log['slack'][] = [
                    'session_mode' => $session->getMode(),
                    'takeover' => str_contains($takeoverUrl, '/channels/widgets/'.$widget->getWidgetId().'/chats?session='.$session->getSessionId()),
                    'last_message' => $lastUserMessage,
                    'reason' => $triggerReason,
                ];

                return true;
            },
        );
        $container->set(SlackNotificationService::class, $slack);

        $discord = $this->createMock(DiscordNotificationService::class);
        $discord->method('notifyWidgetError')->willReturnCallback(function (string $widgetId, string $error): void {
            $this->log['discord'][] = ['error' => $error];
        });
        $container->set(DiscordNotificationService::class, $discord);

        $summaries = $this->createMock(ConversationSummaryRefreshDispatcher::class);
        $summaries->method('dispatch')->willReturnCallback(function (): void {
            ++$this->log['summaries'];
        });
        $container->set(ConversationSummaryRefreshDispatcher::class, $summaries);

        $generatedFiles = $this->createMock(GeneratedFileRegistrar::class);
        $generatedFiles->method('register')->willReturnCallback(function (int $userId, ?string $relativePath, string $type, ?int $messageId = null) use ($container): File {
            $this->log['generated'][] = ['path' => $relativePath, 'type' => $type, 'for_the_answer' => null !== $messageId];
            $file = new File();
            $file->setUserId($userId);
            $file->setFilePath((string) $relativePath);
            $file->setFileType($type);
            $file->setFileName(basename((string) $relativePath));
            $file->setFileSize(10);
            $file->setFileMime('image/png');
            $file->setFileText('');
            $file->setStatus('uploaded');
            $container->get(EntityManagerInterface::class)->persist($file);

            return $file;
        });
        $container->set(GeneratedFileRegistrar::class, $generatedFiles);

        $container->set('http_client', new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->log['http'][] = $method.' '.$url;

            return new MockResponse('', ['http_code' => 500]);
        }));
    }

    private function owner(EntityManagerInterface $em, string $id): User
    {
        $owner = new User();
        $owner->setMail('widget-turn-'.$id.'@characterization.test');
        $owner->setPw(password_hash('password', PASSWORD_BCRYPT));
        $owner->setUserLevel('PRO');
        $owner->setProviderId('local');
        $owner->setCreated(date('YmdHis'));
        $owner->setType('WEB');
        $owner->setEmailVerified(true);
        $em->persist($owner);
        $em->flush();

        return $owner;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function widget(EntityManagerInterface $em, User $owner, string $id, array $case): Widget
    {
        $widget = new Widget();
        $widget->setOwnerId((int) $owner->getId());
        $widget->setOwner($owner);
        $widget->setWidgetId('wdg_char_'.$id);
        $widget->setTaskPromptTopic('tools:widget-default');
        $widget->setName('Characterization');
        $widget->setStatus($case['widget']['status'] ?? 'active');
        $widget->setConfig(($case['config'] ?? []) + ['aiModelId' => 7, 'ragResultLimit' => 8, 'ragMinScore' => 0.4]);
        $em->persist($widget);
        $em->flush();

        return $widget;
    }

    /**
     * The session a case starts from, and the files the visitor sends.
     *
     * @param array<string, mixed> $case
     *
     * @return list<int>
     */
    private function arrange(EntityManagerInterface $em, Widget $widget, User $owner, string $sessionId, array $case): array
    {
        if (!isset($case['session']) && !($case['file'] ?? false)) {
            return [];
        }

        $session = static::getContainer()->get(WidgetSessionService::class)->getOrCreateSession($widget->getWidgetId(), $sessionId);
        if (isset($case['session']['mode'])) {
            $session->setMode($case['session']['mode']);
            $em->flush();
        }
        if (!($case['file'] ?? false)) {
            return [];
        }

        $file = new File();
        $file->setUserId((int) $owner->getId());
        $file->setUserSessionId($session->getId());
        $file->setFilePath('widget/faq.pdf');
        $file->setFileType('pdf');
        $file->setFileName('faq.pdf');
        $file->setFileSize(1234);
        $file->setFileMime('application/pdf');
        $file->setFileText('Opening hours: 9 to 5.');
        $file->setStatus('uploaded');
        $em->persist($file);
        $em->flush();

        return [(int) $file->getId()];
    }

    /**
     * @return array<string, mixed>
     */
    private function response(KernelBrowser $client): array
    {
        $response = $client->getInternalResponse();
        $content = (string) $response->getContent();
        if (!str_starts_with((string) $response->getHeader('Content-Type'), 'text/event-stream')) {
            return ['status' => $response->getStatusCode(), 'json' => $this->normalize(json_decode($content, true))];
        }

        $frames = [];
        foreach (preg_split("/\n\n+/", trim($content)) ?: [] as $frame) {
            if (str_starts_with($frame, 'data: ')) {
                $frames[] = json_decode(substr($frame, 6), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return ['status' => $response->getStatusCode(), 'frames' => $this->normalize($frames), 'run' => $this->replay($frames)];
    }

    /**
     * What a visitor who reloads mid-answer re-attaches to.
     *
     * @param list<array<string, mixed>> $frames
     *
     * @return array<string, mixed>|null
     */
    private function replay(array $frames): ?array
    {
        $runId = $frames[0]['runId'] ?? null;
        if (!is_string($runId)) {
            return null;
        }
        $runs = static::getContainer()->get(ChatRunService::class);
        $replayed = array_map(
            static fn (array $event): mixed => json_decode($event['payload'], true)['status'] ?? null,
            $runs->readEvents($runId),
        );

        return ['status' => $runs->find($runId)?->getStatus(), 'replays' => $replayed];
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(EntityManagerInterface $em, string $widgetId, string $sessionId): array
    {
        $session = $em->getRepository(WidgetSession::class)->findOneBy(['widgetId' => $widgetId, 'sessionId' => $sessionId]);
        if (!$session instanceof WidgetSession) {
            return ['session' => null, 'messages' => []];
        }

        $messages = [];
        $chatId = $session->getChatId();
        foreach (null === $chatId ? [] : $em->getRepository(Message::class)->findBy(['chatId' => $chatId], ['id' => 'ASC']) as $message) {
            $messages[] = [
                'direction' => $message->getDirection(),
                'status' => $message->getStatus(),
                'text' => $message->getText(),
                'topic' => $message->getTopic(),
                'language' => $message->getLanguage(),
                'type' => $message->getMessageType(),
                'provider' => $message->getProviderIndex(),
                'file' => $message->getFile(),
                'files' => count($message->getFiles()),
                'rag_sources' => $message->getMeta('rag_sources'),
            ];
        }

        return [
            'session' => [
                'mode' => $session->getMode(),
                'message_count' => $session->getMessageCount(),
                'preview' => $session->getLastMessagePreview(),
                'chat' => null !== $chatId,
                'country' => $session->getCountry(),
            ],
            'messages' => $messages,
        ];
    }

    /**
     * Database ids, run ids and clock values become stable placeholders,
     * numbered in the order they first appear.
     */
    private function normalize(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $k => $v) {
                $normalized[$k] = $this->normalize($v, is_string($k) ? $k : $key);
            }

            return $normalized;
        }

        return match ($key) {
            'messageId', 'chatId', 'runId', 'files' => null === $value ? null : $this->placeholder($key, (string) $value),
            'timestamp' => '<time>',
            default => $value,
        };
    }

    private function placeholder(string $kind, string $value): string
    {
        $this->placeholders[$kind][$value] ??= sprintf('<%s %d>', $kind, count($this->placeholders[$kind] ?? []) + 1);

        return $this->placeholders[$kind][$value];
    }
}
