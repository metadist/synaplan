<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\Message\Handler\MediaGenerationHandler;
use App\Service\ModelConfigService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\ChatRunner;
use App\Service\Multitask\Execution\Runner\ComposeReplyRunner;
use App\Service\Multitask\Execution\Runner\ExtractTextRunner;
use App\Service\Multitask\Execution\Runner\MediaGenerationRunner;
use App\Service\Multitask\Execution\Runner\Text2SoundRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RunnersTest extends TestCase
{
    private function message(string $text = '', string $fileText = ''): Message&MockObject
    {
        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn($text);
        $m->method('getFileText')->willReturn($fileText);
        $m->method('getLanguage')->willReturn('en');
        $m->method('getFile')->willReturn(0);
        $m->method('getFilePath')->willReturn('');
        $m->method('getFiles')->willReturn(new ArrayCollection());

        return $m;
    }

    private function context(Message $message): NodeContext
    {
        return new NodeContext($message, [], 1, ['language' => 'en']);
    }

    public function testExtractTextReadsResolvedFileText(): void
    {
        $ctx = $this->context($this->message());
        $node = new TaskNode('n1', Capability::ExtractText, [], ['files' => '$message.files']);
        // Simulate resolved file with extracted text via a direct input literal.
        $node = new TaskNode('n1', Capability::ExtractText, [], ['files' => [['path' => 'a.pdf', 'type' => 'pdf', 'text' => 'HELLO DOC']]]);

        $result = (new ExtractTextRunner())->run($node, $ctx);

        self::assertTrue($result->isSuccessful());
        self::assertSame('HELLO DOC', $result->text);
    }

    public function testExtractTextFallsBackToMessageFileText(): void
    {
        $ctx = $this->context($this->message(fileText: 'LEGACY TEXT'));
        $node = new TaskNode('n1', Capability::ExtractText);

        $result = (new ExtractTextRunner())->run($node, $ctx);

        self::assertSame('LEGACY TEXT', $result->text);
    }

    public function testExtractTextFailsWhenNoText(): void
    {
        $result = (new ExtractTextRunner())->run(new TaskNode('n1', Capability::ExtractText), $this->context($this->message()));

        self::assertFalse($result->isSuccessful());
    }

    public function testSummarizeCallsModelAndReturnsContent(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(76);
        $modelConfig->method('getProviderForModel')->willReturn('groq');
        $modelConfig->method('getModelName')->willReturn('gpt-oss-120b');

        $captured = null;
        $aiFacade->method('chat')->willReturnCallback(function (array $messages) use (&$captured): array {
            $captured = $messages;

            return ['content' => 'THE SUMMARY', 'provider' => 'groq', 'model' => 'gpt-oss-120b'];
        });

        $runner = new ChatRunner($aiFacade, $modelConfig, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n2', Capability::Summarize, ['n1'], ['text' => 'long input text']);

        $result = $runner->run($node, $this->context($this->message()));

        self::assertTrue($result->isSuccessful());
        self::assertSame('THE SUMMARY', $result->text);
        // The upstream text reached the model as the user turn.
        self::assertSame('long input text', $captured[1]['content']);
    }

    public function testChatRunnerFailsOnEmptyInput(): void
    {
        $runner = new ChatRunner($this->createMock(AiFacade::class), $this->createMock(ModelConfigService::class), $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::Chat, [], ['text' => '']);

        $result = $runner->run($node, $this->context($this->message()));

        self::assertFalse($result->isSuccessful());
    }

    public function testChatRunnerIsolatesModelFailure(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('chat')->willThrowException(new \RuntimeException('groq 500'));
        $modelConfig = $this->createMock(ModelConfigService::class);

        $runner = new ChatRunner($aiFacade, $modelConfig, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n2', Capability::Summarize, [], ['text' => 'input']);

        $result = $runner->run($node, $this->context($this->message()));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('failed', (string) $result->error);
    }

    public function testText2SoundProducesAudioFile(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('synthesize')->willReturn([
            'relativePath' => '1/000/2026/06/tts_x.mp3',
            'provider' => 'piper',
            'model' => 'piper-multi',
        ]);

        $runner = new Text2SoundRunner($aiFacade, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n3', Capability::Text2Sound, ['n2'], ['text' => 'read this aloud'], ['format' => 'mp3']);

        $result = $runner->run($node, $this->context($this->message()));

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->files);
        self::assertSame('audio', $result->files[0]['type']);
        self::assertSame('/api/v1/files/uploads/1/000/2026/06/tts_x.mp3', $result->files[0]['path']);
        self::assertSame('1/000/2026/06/tts_x.mp3', $result->files[0]['local_path']);
    }

    public function testText2SoundFailsWhenNoText(): void
    {
        $runner = new Text2SoundRunner($this->createMock(AiFacade::class), $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n3', Capability::Text2Sound, [], ['text' => '']);

        $result = $runner->run($node, $this->context($this->message()));

        self::assertFalse($result->isSuccessful());
    }

    public function testMediaGenerationRunnerProducesImageFile(): void
    {
        $handler = $this->createMock(MediaGenerationHandler::class);
        $captured = null;
        $handler->method('handle')->willReturnCallback(function ($msg, $thread, $classification) use (&$captured): array {
            $captured = $classification;

            return [
                'content' => 'Generated image: a dog',
                'metadata' => [
                    'file' => ['path' => '/api/v1/files/uploads/1/000/dog.png', 'type' => 'image'],
                    'local_path' => '1/000/dog.png',
                ],
            ];
        });

        $runner = new MediaGenerationRunner($handler, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::ImageGeneration, [], ['prompt' => 'a happy dog']);

        $result = $runner->run($node, $this->context($this->message('a happy dog')));

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->files);
        self::assertSame('image', $result->files[0]['type']);
        self::assertSame('1/000/dog.png', $result->files[0]['local_path']);
        self::assertSame('tools:pic', $captured['topic']);
        self::assertSame('image', $captured['media_type']);
    }

    public function testMediaGenerationRunnerFailsWhenNoFile(): void
    {
        $handler = $this->createMock(MediaGenerationHandler::class);
        $handler->method('handle')->willReturn(['metadata' => ['error' => 'provider down']]);

        $runner = new MediaGenerationRunner($handler, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::ImageGeneration, [], ['prompt' => 'a dog']);

        $result = $runner->run($node, $this->context($this->message('a dog')));

        self::assertFalse($result->isSuccessful());
    }

    public function testComposeReplyGathersTextAndAttachments(): void
    {
        $ctx = $this->context($this->message());
        $ctx->setResult('n2', NodeResult::ok('final summary'));
        $ctx->setResult('n3', NodeResult::ok(null, [['path' => '/api/v1/files/uploads/x.mp3', 'type' => 'audio']]));

        $node = new TaskNode('n4', Capability::ComposeReply, ['n2', 'n3'], [
            'text' => '$n2.text',
            'attachments' => ['$n3.file'],
        ]);

        $result = (new ComposeReplyRunner())->run($node, $ctx);

        self::assertSame('final summary', $result->text);
        self::assertCount(1, $result->files);
        self::assertSame('audio', $result->files[0]['type']);
    }
}
