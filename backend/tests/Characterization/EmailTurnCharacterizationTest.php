<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Entity\User;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Email\InboundEmailAttachmentStore;
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
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Golden record of the inbound email turn, POST /api/v1/webhooks/email.
 *
 * Every way the turn's output leaves is recorded: the JSON answer to the
 * relay, what reaches the message processor, the stored messages with their
 * metadata, the sender's account, the mail each delivery sent from the
 * mailer's test transport (headers, text, attachments), the speech synthesis, the usage
 * records, the summary refresh, the attachment store and Discord. The
 * processor, speech synthesis, attachment store and Discord are doubles, so
 * no request leaves the process; routing, the controller, the sender and
 * chat lookup, the idempotency check and the mailer are real.
 *
 * Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 */
#[AllowMockObjectsWithoutExpectations]
final class EmailTurnCharacterizationTest extends WebTestCase
{
    private const SNAPSHOT = __DIR__.'/__snapshots__/email_turn.json';
    private const FIXTURE_DIR = 'characterization-email';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    /** @var array{processor: list<mixed>, usage: list<mixed>, speech: list<mixed>, attachments: list<mixed>, discord: list<mixed>, summaries: int, http: list<string>} */
    private array $log = ['processor' => [], 'usage' => [], 'speech' => [], 'attachments' => [], 'discord' => [], 'summaries' => 0, 'http' => []];

    /** @var array<string, array<string, string>> */
    private array $placeholders = [];

    protected function tearDown(): void
    {
        $dir = self::uploadDir();
        if (is_dir($dir)) {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
        parent::tearDown();
    }

    public function testEveryEmailTurnMatchesTheSnapshot(): void
    {
        $actual = [];
        foreach (self::cases() as $id => $case) {
            $actual[$id] = $this->turn($id, $case);
            self::assertSame([], $this->log['http'], $id.': a request left the process');
        }

        foreach (['duplicate_by_message_id', 'duplicate_by_fingerprint'] as $id) {
            self::assertCount(1, $actual[$id]['processor'], $id.': a redelivered mail reached the AI twice.');
        }
        self::assertCount(2, $actual['distinct_mails_with_a_boolean_message_id']['processor'], 'A distinct mail was taken for a redelivery.');

        CleanupSnapshot::assertSame(self::SNAPSHOT, $actual, 'email turn');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function cases(): array
    {
        $rawMime = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nWhen do you open on Saturday?\r\n--b1\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>When do you open on Saturday?</p>\r\n--b1--\r\n";

        return [
            'invalid_json' => ['raw' => 'not json'],
            'missing_fields' => ['mail' => ['body' => '']],
            'plain_mail' => ['mail' => ['message_id' => '<m1@example.com>']],
            'raw_mime_body' => ['mail' => ['body' => $rawMime]],
            'keyword_address' => ['mail' => ['to' => 'smart+support@synaplan.net', 'in_reply_to' => '<earlier@example.com>']],
            'duplicate_by_message_id' => ['mail' => ['message_id' => '<twice@example.com>'], 'deliveries' => 2],
            'duplicate_by_fingerprint' => ['deliveries' => 2],
            'distinct_mails_with_a_boolean_message_id' => ['mail' => ['message_id' => true], 'deliveries' => [[], ['body' => 'And on Sunday?']]],
            'new_sender' => ['registered' => false],
            'rate_limited' => ['limit' => ['allowed' => false, 'limit' => 10, 'used' => 10, 'reset_at' => '2026-10-18 00:00:00']],
            'voice_attachment' => ['mail' => ['attachments' => [['filename' => 'note.ogg', 'content_type' => 'audio/ogg', 'size' => 2048, 'url' => 'https://relay.example.com/files/note.ogg']]]],
            'generated_image' => ['script' => ['result' => self::answer('Here is your cat.', [
                'media_type' => 'image',
                'local_path' => self::FIXTURE_DIR.'/cat.png',
                'file' => ['path' => '/api/v1/files/uploads/'.self::FIXTURE_DIR.'/cat.png', 'type' => 'image'],
                'media_usage' => ['images' => 1],
            ])]],
            'processor_failure' => ['script' => ['result' => ['success' => false, 'error' => 'AI provider failed', 'provider' => 'test']]],
            'processor_exception' => ['script' => ['throw' => true]],
            'debug_sender' => ['domain' => 'metadist.onmicrosoft.com'],
        ];
    }

    /**
     * @param array<string, mixed> $metadata added to the response metadata
     *
     * @return array<string, mixed>
     */
    private static function answer(string $text, array $metadata = []): array
    {
        return [
            'success' => true,
            'classification' => [
                'topic' => 'general',
                'language' => 'en',
                'sorting_usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
                'sorting_provider' => 'test',
                'sorting_model_name' => 'sorter',
            ],
            'response' => [
                'content' => $text,
                'metadata' => $metadata + [
                    'provider' => 'test',
                    'model' => 'test-model',
                    'model_id' => 12,
                    'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4],
                    'docs' => [['slug' => 'using-synaplan', 'title' => 'Using Synaplan', 'url' => 'https://docs.example/using-synaplan']],
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
        $this->log = ['processor' => [], 'usage' => [], 'speech' => [], 'attachments' => [], 'discord' => [], 'summaries' => 0, 'http' => []];
        $this->placeholders = [];
        $this->installDoubles($case);
        $this->writeFixtureFiles();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sender = sprintf('person-%s@%s', str_replace('_', '-', $id), $case['domain'] ?? 'example.com');
        if ($case['registered'] ?? true) {
            $this->registeredUser($em, $sender);
        }

        $mail = ($case['mail'] ?? []) + [
            'from' => strtoupper($sender),
            'to' => 'smart@synaplan.net',
            'subject' => 'Opening hours',
            'body' => 'When do you open on Saturday?',
        ];
        $answers = [];
        $deliveries = $case['deliveries'] ?? 1;
        foreach (is_int($deliveries) ? array_fill(0, $deliveries, []) : $deliveries as $override) {
            $client->request('POST', '/api/v1/webhooks/email', [], [], ['CONTENT_TYPE' => 'application/json'], $case['raw'] ?? json_encode($override + $mail, JSON_THROW_ON_ERROR));
            $answers[] = $this->answerToTheRelay($client) + ['mails' => $this->mails((string) $mail['to'])];
        }
        $em->clear();

        return [
            'answers' => $answers,
            'processor' => $this->log['processor'],
            'stored' => $this->stored($em, $sender),
            'speech' => $this->log['speech'],
            'usage' => $this->log['usage'],
            'summary_refreshes' => $this->log['summaries'],
            'attachment_store' => $this->log['attachments'],
            'discord' => $this->log['discord'],
        ];
    }

    /**
     * @param array<string, mixed> $case
     */
    private function installDoubles(array $case): void
    {
        $container = static::getContainer();
        $script = ($case['script'] ?? []) + ['result' => self::answer('We open at 9 on Saturday. See [Doc:using-synaplan].')];

        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('process')->willReturnCallback(function (Message $message) use ($script): array {
            $this->log['processor'][] = ['text' => $message->getText(), 'status' => $message->getStatus(), 'voice_reply' => $message->getMeta('voice_reply')];
            if ($script['throw'] ?? false) {
                throw new \RuntimeException('The processor failed.');
            }

            return $script['result'];
        });
        $container->set(MessageProcessor::class, $processor);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn($case['limit'] ?? ['allowed' => true]);
        $rateLimits->method('recordUsage')->willReturnCallback(function (User $user, string $action, array $metadata = []): RecordedUsage {
            unset($metadata['latency']);
            ksort($metadata);
            $this->log['usage'][] = ['action' => $action, 'metadata' => $metadata];

            return new RecordedUsage('0.001000', '0.000800', 3, 4, 7);
        });
        $container->set(RateLimitService::class, $rateLimits);

        $ai = $this->createMock(AiFacade::class);
        $ai->method('synthesize')->willReturnCallback(function (string $text, string $language, ?int $userId = null, array $options = []): array {
            $this->log['speech'][] = ['text' => $text, 'language' => $language, 'format' => $options['format'] ?? null];

            return ['relativePath' => self::FIXTURE_DIR.'/voice.mp3', 'provider' => 'test', 'model' => 'tts', 'model_id' => 3, 'text_length' => mb_strlen($text)];
        });
        $container->set(AiFacade::class, $ai);

        $attachments = $this->createMock(InboundEmailAttachmentStore::class);
        $attachments->method('attach')->willReturnCallback(function (Message $message, User $user, array $items): array {
            $this->log['attachments'][] = array_map(static fn (array $item): string => $item['filename'].' '.$item['content_type'], $items);

            return [];
        });
        $container->set(InboundEmailAttachmentStore::class, $attachments);

        $discord = $this->createMock(DiscordNotificationService::class);
        foreach (['notifyDuplicateEmailWebhook', 'notifyEmailError', 'notifyEmailSuccess'] as $method) {
            $discord->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method): void {
                $this->log['discord'][] = $method.('notifyEmailError' === $method ? ' '.$arguments[0] : '');
            });
        }
        $container->set(DiscordNotificationService::class, $discord);

        $summaries = $this->createMock(ConversationSummaryRefreshDispatcher::class);
        $summaries->method('dispatch')->willReturnCallback(function (): void {
            ++$this->log['summaries'];
        });
        $container->set(ConversationSummaryRefreshDispatcher::class, $summaries);

        $container->set('http_client', new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->log['http'][] = $method.' '.$url;

            return new MockResponse('', ['http_code' => 500]);
        }));
    }

    private static function uploadDir(): string
    {
        return dirname(__DIR__, 2).'/var/uploads/'.self::FIXTURE_DIR;
    }

    private function writeFixtureFiles(): void
    {
        if (!is_dir(self::uploadDir())) {
            mkdir(self::uploadDir(), 0o775, true);
        }
        file_put_contents(self::uploadDir().'/cat.png', (string) base64_decode(self::PNG, true));
        file_put_contents(self::uploadDir().'/voice.mp3', 'ID3 voice');
    }

    private function registeredUser(EntityManagerInterface $em, string $email): void
    {
        $user = new User();
        $user->setMail($email);
        $user->setPw(password_hash('password', PASSWORD_BCRYPT));
        $user->setUserLevel('PRO');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setType('WEB');
        $user->setEmailVerified(true);
        $em->persist($user);
        $em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function answerToTheRelay(KernelBrowser $client): array
    {
        $response = $client->getInternalResponse();

        return ['status' => $response->getStatusCode(), 'json' => $this->normalize(json_decode((string) $response->getContent(), true))];
    }

    /**
     * The sender's account and every message of the chats their mail reached.
     *
     * @return array<string, mixed>
     */
    private function stored(EntityManagerInterface $em, string $sender): array
    {
        $chatIds = [];
        $user = null;
        foreach ($em->getRepository(MessageMeta::class)->findBy(['metaKey' => 'from_email', 'metaValue' => $sender]) as $meta) {
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
                    $meta[$entry->getMetaKey()] = 'email_fingerprint' === $entry->getMetaKey() ? '<fingerprint>' : $entry->getMetaValue();
                }
                ksort($meta);
                $messages[] = [
                    'direction' => $message->getDirection(),
                    'status' => $message->getStatus(),
                    'text' => $message->getText(),
                    'topic' => $message->getTopic(),
                    'language' => $message->getLanguage(),
                    'type' => $message->getMessageType(),
                    'provider' => $message->getProviderIndex(),
                    'file' => [$message->getFile(), $message->getFilePath(), $message->getFileType()],
                    'meta' => $meta,
                ];
            }
        }

        return [
            'account' => $user instanceof User ? ['level' => $user->getUserLevel(), 'type' => $user->getType(), 'details' => $user->getUserDetails()] : null,
            'chats' => count($chatIds),
            'messages' => $messages,
        ];
    }

    /**
     * The mails one delivery sent; the test client clears the log between requests.
     *
     * @return list<array<string, mixed>>
     */
    private function mails(string $relayRecipient): array
    {
        $mails = [];
        foreach (self::getMailerMessages() as $mail) {
            self::assertInstanceOf(Email::class, $mail);
            $mails[] = [
                'to' => array_map(static fn ($address): string => $address->getAddress(), $mail->getTo()),
                'reply_to' => array_map(static fn ($address): string => $address->getAddress() === $relayRecipient ? '<relay recipient>' : '<fallback address>', $mail->getReplyTo()),
                'subject' => $mail->getSubject(),
                'in_reply_to' => $mail->getHeaders()->get('In-Reply-To')?->getBodyAsString(),
                'references' => $mail->getHeaders()->get('References')?->getBodyAsString(),
                'text' => preg_replace('/Processing time: [0-9.]+ seconds/', 'Processing time: <time> seconds', (string) $mail->getTextBody()),
                'attachments' => array_map(
                    static fn (DataPart $part): string => sprintf('%s %s%s', $part->getFilename(), $part->getContentType(), $part->hasContentId() ? ' inline' : ''),
                    $mail->getAttachments(),
                ),
            ];
        }

        return $mails;
    }

    /**
     * Database ids become stable placeholders, numbered in the order they first appear.
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
            'message_id', 'chat_id' => null === $value ? null : $this->placeholder($key, (string) $value),
            default => $value,
        };
    }

    private function placeholder(string $kind, string $value): string
    {
        $this->placeholders[$kind][$value] ??= sprintf('<%s %d>', $kind, count($this->placeholders[$kind] ?? []) + 1);

        return $this->placeholders[$kind][$value];
    }
}
