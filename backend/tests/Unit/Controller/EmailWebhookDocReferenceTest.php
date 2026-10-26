<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Email\EmailChatService;
use App\Service\Email\EmailWebhookIdempotencyService;
use App\Service\Email\InboundEmailAttachmentStore;
use App\Service\Email\InternalEmailService;
use App\Service\Email\RawMimeEmailParser;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ExternalReplyReferences;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use App\Service\WhatsApp\Turn\WhatsAppTurn;
use App\Tests\Support\EmailWebhookControllerFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;

final class EmailWebhookDocReferenceTest extends TestCase
{
    public function testMailBodyResolvesDocTagsWhileTheStoredReplyKeepsThem(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);
        $user->method('isActive')->willReturn(true);

        $chat = $this->createMock(Chat::class);
        $chat->method('getId')->willReturn(9);

        $docs = [[
            'slug' => 'using-synaplan',
            'title' => 'Using Synaplan',
            'url' => 'https://docs.example/using-synaplan',
        ]];
        $raw = 'See [Doc:using-synaplan].';
        $linked = '[Using Synaplan](https://docs.example/using-synaplan)';

        $references = $this->createMock(ExternalReplyReferences::class);
        $references->expects($this->once())
            ->method('resolve')
            ->with($raw, $user)
            ->willReturn($linked);

        $mailer = $this->createMock(InternalEmailService::class);
        $mailer->expects($this->once())
            ->method('sendAiResponseEmail')
            ->with('person@example.com', 'Question', $linked);

        $persisted = [];
        $nextId = 10;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted, &$nextId): void {
            $persisted[] = $entity;
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

        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('process')->willReturn([
            'success' => true,
            'classification' => ['topic' => 'synaplan', 'language' => 'en'],
            'response' => [
                'content' => $raw,
                'metadata' => [
                    'provider' => 'test',
                    'model' => 'test-model',
                    'docs' => $docs,
                ],
            ],
        ]);

        $mime = $this->createMock(RawMimeEmailParser::class);
        $mime->method('looksLikeRawMime')->willReturn(false);

        $controller = EmailWebhookControllerFactory::build([
            'em' => $em,
            'processor' => $processor,
            'idempotency' => $idempotency,
            'limits' => $limits,
            'emailChats' => $emailChats,
            'mailer' => $mailer,
            'discord' => $this->createStub(DiscordNotificationService::class),
            'ai' => $this->createStub(AiFacade::class),
            'models' => $this->createStub(ModelConfigService::class),
            'mime' => $mime,
            'attachments' => $this->createStub(InboundEmailAttachmentStore::class),
            'summaries' => $this->createStub(ConversationSummaryRefreshDispatcher::class),
            'errors' => $this->createStub(ChatErrorPresenter::class),
            'references' => $references,
            'whatsApp' => $this->createStub(WhatsAppTurn::class),
            'uploadDir' => sys_get_temp_dir(),
        ]);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR),
        );
        $container = new Container();
        $container->set('serializer', $serializer);
        $controller->setContainer($container);

        $request = Request::create('/api/v1/webhooks/email', 'POST', content: json_encode([
            'from' => 'person@example.com',
            'to' => 'smart@synaplan.net',
            'subject' => 'Question',
            'body' => 'How do I use Synaplan?',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->email($request);
        $this->assertSame(200, $response->getStatusCode());

        $outgoing = array_values(array_filter(
            $persisted,
            static fn (object $entity): bool => $entity instanceof Message && 'OUT' === $entity->getDirection(),
        ));
        $this->assertCount(1, $outgoing);
        $this->assertInstanceOf(Message::class, $outgoing[0]);
        $this->assertSame($raw, $outgoing[0]->getText());
        $this->assertSame(json_encode($docs, JSON_UNESCAPED_SLASHES), $outgoing[0]->getMeta('docs'));
    }
}
