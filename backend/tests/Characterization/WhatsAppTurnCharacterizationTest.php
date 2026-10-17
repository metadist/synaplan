<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Entity\User;
use App\Realtime\Notifier\ChatActivityNotifier;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\File\FileProcessor;
use App\Service\Message\MessageProcessor;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use App\Tests\Characterization\Support\CleanupSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Golden record of the WhatsApp turn, POST /api/v1/webhooks/whatsapp.
 *
 * Every way the turn's output leaves is recorded: the webhook's answer,
 * every Graph API request (replies, media sends, read receipts and media
 * fetches, from a double of the HTTP client), what reaches the message
 * processor, the stored messages with their metadata and files, the
 * transcription and speech synthesis, the usage records, Discord, the chat
 * activity push and the summary refresh. The processor, transcription,
 * speech, Discord and the realtime push are doubles; routing, the
 * controller, the phone account lookup, the duplicate guard and the
 * service itself are real, and no other request leaves the process.
 *
 * Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 */
#[AllowMockObjectsWithoutExpectations]
final class WhatsAppTurnCharacterizationTest extends WebTestCase
{
    private const SNAPSHOT = __DIR__.'/__snapshots__/whatsapp_turn.json';
    private const PHONE_NUMBER_ID = '1098765';
    private const TIMESTAMP = 1792240000;

    /** The service is enabled and links to a fixed app, whatever the machine's environment says. */
    private const ENVIRONMENT = [
        'WHATSAPP_ENABLED' => 'true',
        'WHATSAPP_ACCESS_TOKEN' => 'characterization',
        'WHATSAPP_GRAPH_API_BASE_URL' => 'https://graph.characterization.test',
        'APP_URL' => 'https://app.characterization.test',
    ];

    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $savedEnvironment = [];

    /** @var array<string, list<mixed>|int> */
    private array $log = [];

    /** @var list<string> */
    private array $writtenFiles = [];

    /**
     * The duplicate guard remembers message ids in the app cache across test
     * runs, so every run sends fresh ids and strips this token from the record.
     */
    private string $run = '';

    protected function setUp(): void
    {
        foreach (self::ENVIRONMENT as $name => $value) {
            $this->savedEnvironment[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => [$env, $server]) {
            if (null === $env) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            if (null === $server) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
        foreach ($this->writtenFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testEveryWhatsAppTurnMatchesTheSnapshot(): void
    {
        $this->run = '.run'.bin2hex(random_bytes(4));
        $actual = [];
        foreach (self::cases() as $id => $case) {
            $actual[$id] = $this->turn($id, $case);
        }

        self::assertCount(1, $actual['duplicate_webhook']['processor'], 'A redelivered webhook reached the AI twice.');

        CleanupSnapshot::assertSame(self::SNAPSHOT, $actual, 'WhatsApp turn');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function cases(): array
    {
        return [
            'text' => ['message' => self::text('Wann habt ihr am Samstag offen?')],
            'duplicate_webhook' => ['message' => self::text('Hallo?'), 'deliveries' => 2],
            'unsupported_type' => ['message' => ['type' => 'reaction', 'reaction' => ['emoji' => '👍', 'message_id' => 'wamid.earlier']]],
            'verification_code' => ['message' => self::text('ab12c'), 'verification' => 'AB12C'],
            'rate_limited' => ['message' => self::text('Noch eine Frage'), 'limit' => ['allowed' => false, 'limit' => 5, 'used' => 5, 'limit_type' => 'lifetime']],
            'image_without_caption' => ['message' => ['type' => 'image', 'image' => ['id' => 'media.image', 'mime_type' => 'image/jpeg']], 'media' => ['image/jpeg', 'jpeg-bytes']],
            'image_with_caption' => ['message' => ['type' => 'image', 'image' => ['id' => 'media.image', 'mime_type' => 'image/jpeg', 'caption' => 'Was ist das?']], 'media' => ['image/jpeg', 'jpeg-bytes']],
            'voice_message' => ['message' => ['type' => 'audio', 'audio' => ['id' => 'media.voice', 'mime_type' => 'audio/ogg', 'voice' => true]], 'media' => ['audio/ogg', 'ogg-bytes'], 'transcript' => 'Wann habt ihr offen?'],
            'media_download_failure' => ['message' => ['type' => 'image', 'image' => ['id' => 'media.missing', 'mime_type' => 'image/jpeg']]],
            'processor_failure' => ['message' => self::text('Hallo'), 'script' => ['result' => ['success' => false, 'error' => 'AI provider failed', 'provider' => 'test']]],
            'web_search_citations' => ['message' => self::text('Was gibt es Neues?'), 'script' => ['result' => self::answer('Hier ist das Neueste.', [], [
                'query' => 'news today',
                'results' => [['title' => 'News', 'url' => 'https://news.example/today', 'description' => 'Today']],
            ])]],
            'generated_image' => ['message' => self::text('Mal mir eine Katze'), 'script' => ['result' => self::answer('Hier ist deine Katze.', [
                'file' => ['path' => '/api/v1/files/uploads/2/generated/cat.png', 'type' => 'image'],
                'media_usage' => ['images' => 1],
            ])]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(string $body): array
    {
        return ['type' => 'text', 'text' => ['body' => $body]];
    }

    /**
     * @param array<string, mixed>      $metadata
     * @param array<string, mixed>|null $searchResults
     *
     * @return array<string, mixed>
     */
    private static function answer(string $text, array $metadata = [], ?array $searchResults = null): array
    {
        return [
            'success' => true,
            'classification' => ['topic' => 'general', 'language' => 'de'],
            'search_results' => $searchResults,
            'response' => [
                'content' => $text,
                'metadata' => $metadata + ['provider' => 'test', 'model' => 'test-model', 'model_id' => 12, 'usage' => ['total' => 7]],
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
        $this->log = ['processor' => [], 'graph' => [], 'usage' => [], 'speech' => [], 'transcriptions' => [], 'discord' => [], 'activity' => [], 'summaries' => 0];
        $this->installDoubles($case);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $phone = '49151'.str_pad((string) (crc32($id) % 10000000), 7, '0', STR_PAD_LEFT);
        if (isset($case['verification'])) {
            $this->userAwaitingVerification($em, $id, $phone, $case['verification']);
        }

        $answers = [];
        for ($delivery = 0; $delivery < ($case['deliveries'] ?? 1); ++$delivery) {
            $client->request('POST', '/api/v1/webhooks/whatsapp', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->payload($id, $phone, $case['message']), JSON_THROW_ON_ERROR));
            $answers[] = $this->webhookAnswer($client);
        }
        $em->clear();

        return $this->withoutRunToken([
            'answers' => $answers,
            'processor' => $this->log['processor'],
            'graph' => $this->log['graph'],
            'stored' => $this->stored($em, $phone),
            'transcriptions' => $this->log['transcriptions'],
            'speech' => $this->log['speech'],
            'usage' => $this->log['usage'],
            'discord' => $this->log['discord'],
            'chat_activity' => $this->log['activity'],
            'summary_refreshes' => $this->log['summaries'],
        ]);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function withoutRunToken(array $record): array
    {
        return json_decode(str_replace($this->run, '', (string) json_encode($record, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function payload(string $id, string $phone, array $message): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '4930123456', 'phone_number_id' => self::PHONE_NUMBER_ID],
            'contacts' => [['profile' => ['name' => 'Ada'], 'wa_id' => $phone]],
            'messages' => [$message + ['from' => $phone, 'id' => 'wamid.'.$id.$this->run, 'timestamp' => (string) self::TIMESTAMP]],
        ]]]]]];
    }

    /**
     * @param array<string, mixed> $case
     */
    private function installDoubles(array $case): void
    {
        $container = static::getContainer();
        $script = ($case['script'] ?? []) + ['result' => self::answer('Samstags von 9 bis 14 Uhr.')];

        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('processStream')->willReturnCallback(function (Message $message, callable $stream, ?callable $status = null, array $options = []) use ($script): array {
            ksort($options);
            $this->log['processor'][] = ['text' => $message->getText(), 'status' => $message->getStatus(), 'files' => count($message->getFiles()), 'options' => $options];

            return $script['result'];
        });
        $container->set(MessageProcessor::class, $processor);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn($case['limit'] ?? ['allowed' => true]);
        $rateLimits->method('recordUsage')->willReturnCallback(function (User $user, string $action, array $metadata = []): RecordedUsage {
            ksort($metadata);
            unset($metadata['recorded_chat_usage']);
            $this->log['usage'][] = ['action' => $action, 'metadata' => $metadata];

            return new RecordedUsage('0.001000', '0.000800', 3, 4, 7);
        });
        $container->set(RateLimitService::class, $rateLimits);

        $files = $this->createMock(FileProcessor::class);
        $files->method('extractText')->willReturnCallback(function (string $path, string $type) use ($case): array {
            $this->log['transcriptions'][] = $type;

            return [$case['transcript'] ?? '', []];
        });
        $container->set(FileProcessor::class, $files);

        $ai = $this->createMock(AiFacade::class);
        $ai->method('synthesize')->willReturnCallback(function (string $text, string $language, ?int $userId = null, array $options = []): array {
            $this->log['speech'][] = ['text' => $text, 'language' => $language, 'format' => $options['format'] ?? null];

            return ['relativePath' => '2/tts/answer.mp3', 'provider' => 'test', 'model' => 'tts', 'model_id' => 3, 'text_length' => mb_strlen($text)];
        });
        $container->set(AiFacade::class, $ai);

        $discord = $this->createMock(DiscordNotificationService::class);
        foreach (['notifyWhatsAppError', 'notifyWhatsAppSuccess'] as $method) {
            $discord->method($method)->willReturnCallback(function (string $kind) use ($method): void {
                $this->log['discord'][] = $method.' '.$kind;
            });
        }
        $container->set(DiscordNotificationService::class, $discord);

        $activity = $this->createMock(ChatActivityNotifier::class);
        $activity->method('publishActivity')->willReturnCallback(function (mixed $chat, int $userId, string $direction, ?string $preview = null): void {
            $this->log['activity'][] = [$direction, $preview];
        });
        $container->set(ChatActivityNotifier::class, $activity);

        $summaries = $this->createMock(ConversationSummaryRefreshDispatcher::class);
        $summaries->method('dispatch')->willReturnCallback(function (): void {
            ++$this->log['summaries'];
        });
        $container->set(ConversationSummaryRefreshDispatcher::class, $summaries);

        $container->set('http_client', new MockHttpClient(fn (string $method, string $url, array $options): MockResponse => $this->graph($method, $url, $options, $case)));
    }

    /**
     * The Graph API double: media lookups and downloads, and every message
     * send answered with a message id.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $case
     */
    private function graph(string $method, string $url, array $options, array $case): MockResponse
    {
        $path = (string) preg_replace('#^https?://[^/]+(/v[0-9.]+)?#', '', $url);
        $body = isset($options['body']) && is_string($options['body']) ? json_decode($options['body'], true) : null;
        $this->log['graph'][] = [$method, str_contains($url, 'lookaside.example') ? 'lookaside'.$path : $path, $this->normalize($body)];

        if ('GET' === $method && str_contains($url, 'lookaside.example')) {
            return new MockResponse($case['media'][1] ?? '', ['response_headers' => ['content-type' => $case['media'][0] ?? 'application/octet-stream']]);
        }
        if ('GET' === $method && str_starts_with($path, '/media.')) {
            return isset($case['media'])
                ? new MockResponse((string) json_encode(['url' => 'https://lookaside.example/'.ltrim($path, '/'), 'mime_type' => $case['media'][0], 'file_size' => strlen($case['media'][1])]))
                : new MockResponse('{"error":{"message":"Unknown media"}}', ['http_code' => 404]);
        }

        return new MockResponse((string) json_encode(['messages' => [['id' => 'wamid.reply.'.count($this->log['graph'])]], 'success' => true]));
    }

    private function userAwaitingVerification(EntityManagerInterface $em, string $id, string $phone, string $code): void
    {
        $user = new User();
        $user->setMail('whatsapp-turn-'.$id.'@characterization.test');
        $user->setPw(password_hash('password', PASSWORD_BCRYPT));
        $user->setUserLevel('NEW');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setType('WEB');
        $user->setUserDetails(['phone_verification' => ['code' => $code, 'phone_number' => '+'.$phone, 'expires_at' => time() + 300]]);
        $em->persist($user);
        $em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookAnswer(KernelBrowser $client): array
    {
        $response = $client->getInternalResponse();
        $json = json_decode((string) $response->getContent(), true);
        foreach ($json['responses'] ?? [] as $i => $answer) {
            if (isset($answer['user_id'])) {
                $json['responses'][$i]['user_id'] = '<user>';
            }
        }

        return ['status' => $response->getStatusCode(), 'json' => $json];
    }

    /**
     * Every message of the chats the sender's messages reached, and the
     * account they belong to.
     *
     * @return array<string, mixed>
     */
    private function stored(EntityManagerInterface $em, string $phone): array
    {
        $chatIds = [];
        $user = null;
        foreach ($em->getRepository(MessageMeta::class)->findBy(['metaKey' => 'from_phone', 'metaValue' => $phone]) as $meta) {
            $message = $meta->getMessage();
            if (null !== $message?->getChatId()) {
                $chatIds[$message->getChatId()] = true;
                $user ??= $em->find(User::class, $message->getUserId());
            }
        }

        $messages = [];
        foreach (array_keys($chatIds) as $chatId) {
            foreach ($em->getRepository(Message::class)->findBy(['chatId' => $chatId], ['id' => 'ASC']) as $message) {
                $meta = [];
                foreach ($message->getMetadata() as $entry) {
                    $meta[$entry->getMetaKey()] = 'original_user_id' === $entry->getMetaKey() && $entry->getMetaValue() === (string) $message->getUserId()
                        ? '<the message account>'
                        : $entry->getMetaValue();
                }
                ksort($meta);
                $files = [];
                foreach ($message->getFiles() as $file) {
                    $this->writtenFiles[] = dirname(__DIR__, 2).'/var/uploads/'.$file->getFilePath();
                    $files[] = [$file->getFileType(), $file->getFileMime(), $file->getStatus()];
                }
                $messages[] = [
                    'direction' => $message->getDirection(),
                    'status' => $message->getStatus(),
                    'text' => $message->getText(),
                    'topic' => $message->getTopic(),
                    'language' => $message->getLanguage(),
                    'type' => $message->getMessageType(),
                    'provider' => $message->getProviderIndex(),
                    'file' => [$message->getFile(), $this->normalize($message->getFilePath()), $message->getFileType()],
                    'files' => $files,
                    'meta' => $this->normalize($meta),
                ];
            }
        }

        return [
            'account' => $user instanceof User ? ['level' => $user->getUserLevel(), 'type' => $user->getType()] : null,
            'chats' => count($chatIds),
            'messages' => $messages,
        ];
    }

    /**
     * Dated upload paths become a stable placeholder.
     */
    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->normalize(...), $value);
        }
        if (!is_string($value)) {
            return $value;
        }

        return (string) preg_replace('#[0-9]+/[0-9]{3}/[0-9]{5}/[0-9]{4}/[0-9]{2}/[A-Za-z0-9_.-]+#', '<upload path>', $value);
    }
}
