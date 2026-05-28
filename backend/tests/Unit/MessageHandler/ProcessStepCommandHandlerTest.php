<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Message;
use App\Message\ProcessStepCommand;
use App\MessageHandler\ProcessStepCommandHandler;
use App\Repository\MessageRepository;
use App\Service\Message\InferenceRouter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ProcessStepCommandHandlerTest extends TestCase
{
    public function testHandlesImageGenerationStep(): void
    {
        $originalMessage = $this->createMock(Message::class);
        $originalMessage->method('getTrackingId')->willReturn(999);
        $originalMessage->method('getLanguage')->willReturn('de');
        $originalMessage->method('getUserId')->willReturn(1);
        $originalMessage->method('getChatId')->willReturn(42);

        $outMessage = $this->createMock(Message::class);
        $outMessage->method('getId')->willReturn(200);
        $outMessage->expects($this->once())->method('setMeta')
            ->with('deferred_step_1', $this->isType('string'));

        $messageRepo = $this->createMock(EntityRepository::class);
        $messageRepo->method('find')->willReturn($originalMessage);
        $messageRepo->method('findOneBy')->willReturn($outMessage);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($messageRepo);
        $em->expects($this->once())->method('flush');

        $router = $this->createMock(InferenceRouter::class);
        $router->expects($this->once())->method('route')->willReturn([
            'content' => '',
            'metadata' => [
                'file' => ['path' => '/api/v1/files/uploads/img.png', 'type' => 'image'],
                'provider' => 'openai',
                'model' => 'dall-e-3',
            ],
        ]);

        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($httpResponse);

        $historyRepo = $this->createMock(MessageRepository::class);
        $historyRepo->method('findChatHistory')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);

        $handler = new ProcessStepCommandHandler($em, $historyRepo, $router, $httpClient, $logger);

        $command = new ProcessStepCommand(
            conversationId: 42,
            originalMsgId: 100,
            userId: 1,
            stepIndex: 1,
            stepData: [
                'id' => 'step_2',
                'capability' => 'IMAGE_GENERATION',
                'web_search' => false,
                'media_type' => 'image',
                'metadata' => [],
            ],
            previousOutput: 'Previous step generated text about cats',
        );

        $handler($command);
    }

    public function testSkipsWhenOriginalMessageNotFound(): void
    {
        $messageRepo = $this->createMock(EntityRepository::class);
        $messageRepo->method('find')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($messageRepo);

        $router = $this->createMock(InferenceRouter::class);
        $router->expects($this->never())->method('route');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $historyRepo = $this->createMock(MessageRepository::class);
        $logger = $this->createMock(LoggerInterface::class);

        $handler = new ProcessStepCommandHandler($em, $historyRepo, $router, $httpClient, $logger);

        $command = new ProcessStepCommand(
            conversationId: 42,
            originalMsgId: 999,
            userId: 1,
            stepIndex: 1,
            stepData: ['id' => 'step_2', 'capability' => 'CHAT'],
        );

        $handler($command);
    }
}
