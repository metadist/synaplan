<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Exception\NoSpeakableTextException;
use App\AI\Provider\PiperProvider;
use App\AI\Service\AiFacade;
use App\Controller\WebhookController;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\ConversationSummaryRefreshDispatcher;
use App\Service\DiscordNotificationService;
use App\Service\Email\InboundEmailAttachmentStore;
use App\Service\Email\RawMimeEmailParser;
use App\Service\EmailChatService;
use App\Service\EmailWebhookIdempotencyService;
use App\Service\InternalEmailService;
use App\Service\Media\GeneratedFileMetadataNormalizer;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ExternalReplyReferences;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Email voice reply (#2283): a German classification language must reach
 * AiFacade::synthesize as `de` — not a hardcoded English default.
 */
final class EmailVoiceReplyLanguageTest extends TestCase
{
    public function testGermanReplyLanguageSelectsGermanPiperVoice(): void
    {
        $base = sys_get_temp_dir().'/syn_email_tts_'.uniqid();
        $provider = new PiperProvider(
            $this->createMock(HttpClientInterface::class),
            'http://tts:10200',
            new NullLogger(),
            new Filesystem(),
            $base.'/temp',
            $base.'/uploads',
        );

        $method = new \ReflectionMethod($provider, 'resolveVoice');
        $voice = (string) $method->invoke($provider, [
            'language' => 'de',
            'model' => 'en_US-lessac-medium',
        ]);

        self::assertSame('de_DE-kerstin-low', $voice);
        (new Filesystem())->remove($base);
    }

    public function testEmailVoiceReplyPassesClassificationLanguageToSynthesize(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())
            ->method('synthesize')
            ->with(
                'Guten Tag, hier ist die Antwort.',
                'de',
                4,
                $this->callback(static function (array $opts): bool {
                    return 'mp3' === ($opts['format'] ?? null);
                }),
            )
            ->willReturn([
                'relativePath' => '4/000/00004/2026/03/tts_test.mp3',
                'provider' => 'piper',
                'model' => 'de_DE-kerstin-low',
                'model_id' => 7,
                'text_length' => 30,
            ]);

        $response = $this->sendVoiceEmail(
            $aiFacade,
            $this->createStub(DiscordNotificationService::class),
            'Guten Tag, hier ist die Antwort.',
            'person@example.com',
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testNothingToSpeakSendsTheTextEmailWithoutAFailureAlert(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())
            ->method('synthesize')
            ->willThrowException(new NoSpeakableTextException());

        // The debug sender gets a Discord alert for every real email failure.
        $discord = $this->createMock(DiscordNotificationService::class);
        $discord->expects($this->never())->method('notifyEmailError');

        $response = $this->sendVoiceEmail(
            $aiFacade,
            $discord,
            "```\nprint('hi')\n```",
            'tester@metadist.onmicrosoft.com',
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function sendVoiceEmail(
        AiFacade $aiFacade,
        DiscordNotificationService $discord,
        string $answer,
        string $from,
    ): Response {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);

        $chat = $this->createMock(Chat::class);
        $chat->method('getId')->willReturn(9);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->with('TEXT2SOUND', 4)->willReturn(7);
        $modelConfig->method('getProviderForModel')->with(7)->willReturn('piper');

        $nextId = 10;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$nextId): void {
            if ($entity instanceof Message && null === $entity->getId()) {
                $id = new \ReflectionProperty(Message::class, 'id');
                $id->setValue($entity, ++$nextId);
            }
        });

        $idempotency = $this->createMock(EmailWebhookIdempotencyService::class);
        $idempotency->method('findDuplicate')->willReturn([
            'existing' => null,
            'fingerprint' => 'fp',
            'normalized_message_id' => null,
        ]);

        $limits = $this->createMock(RateLimitService::class);
        $limits->method('checkLimit')->willReturn(['allowed' => true]);
        $limits->method('recordUsage')->willReturn(new RecordedUsage('0.001000', '0.001000', 1, 1, 2));

        $emailChats = $this->createMock(EmailChatService::class);
        $emailChats->method('parseEmailKeyword')->willReturn(null);
        $emailChats->method('findOrCreateUserFromEmail')->willReturn(['user' => $user]);
        $emailChats->method('findOrCreateChatContext')->willReturn($chat);

        $attachmentStore = $this->createMock(InboundEmailAttachmentStore::class);
        $attachmentStore->expects($this->once())->method('attach');

        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('process')->willReturn([
            'success' => true,
            'classification' => ['topic' => 'CHAT', 'language' => 'de'],
            'response' => [
                'content' => $answer,
                'metadata' => [
                    'provider' => 'test',
                    'model' => 'test-model',
                ],
            ],
        ]);

        $mailer = $this->createMock(InternalEmailService::class);
        $mailer->expects($this->once())->method('sendAiResponseEmail');

        $mime = $this->createMock(RawMimeEmailParser::class);
        $mime->method('looksLikeRawMime')->willReturn(false);

        $references = $this->createMock(ExternalReplyReferences::class);
        $references->method('resolve')->willReturnCallback(
            static fn (string $text): string => $text,
        );

        $controller = new WebhookController(
            $em,
            $processor,
            $idempotency,
            $limits,
            $this->createStub(WhatsAppService::class),
            $emailChats,
            $mailer,
            $discord,
            new NullLogger(),
            'verify-token',
            $aiFacade,
            $modelConfig,
            new GeneratedFileMetadataNormalizer(),
            $mime,
            $attachmentStore,
            $this->createStub(ConversationSummaryRefreshDispatcher::class),
            $this->createStub(ChatErrorPresenter::class),
            $references,
        );

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR),
        );
        $container = new Container();
        $container->set('serializer', $serializer);
        $container->set('parameter_bag', new ParameterBag(['kernel.project_dir' => '/tmp']));
        $controller->setContainer($container);

        $request = Request::create('/api/v1/webhooks/email', 'POST', content: json_encode([
            'from' => $from,
            'to' => 'smart@synaplan.net',
            'subject' => 'Frage',
            'body' => 'Bitte antworte per Sprache.',
            'attachments' => [[
                'filename' => 'voice.ogg',
                'content_type' => 'audio/ogg',
                'content' => base64_encode('fake-audio'),
            ]],
        ], JSON_THROW_ON_ERROR));

        return $controller->email($request);
    }
}
