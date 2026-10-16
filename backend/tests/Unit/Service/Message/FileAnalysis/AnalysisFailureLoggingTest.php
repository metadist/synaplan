<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\FileAnalysis;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\Message\FileAnalysis\AnalysisContext;
use App\Service\Message\FileAnalysis\AnalysisReply;
use App\Service\Message\FileAnalysis\DocumentAnalysis;
use App\Service\Message\FileAnalysis\VoiceMessageReply;
use App\Service\ModelConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Anything that fails once the model call started — the answer itself or the
 * `complete` status after it (a client that went away) — is logged as an
 * analysis failure with the files involved, then rethrown to the pipeline.
 */
final class AnalysisFailureLoggingTest extends TestCase
{
    public function testADocumentAnalysisThatFailsOnItsCompleteStatusIsLoggedAndRethrown(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'FileAnalysisHandler: Chat analysis failed',
            self::callback(static fn (array $context): bool => 'report.pdf' === $context['files'] && 'client gone' === $context['error']),
        );

        $analysis = new DocumentAnalysis($this->ai(), $this->models(), $logger);

        $this->expectExceptionObject(new \RuntimeException('client gone'));
        $analysis->analyze($this->contextFailingOnComplete(), [['name' => 'report.pdf', 'type' => 'pdf', 'text' => 'Revenue rose.']]);
    }

    public function testAVoiceReplyThatFailsOnItsCompleteStatusIsLoggedAndRethrown(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'FileAnalysisHandler: Voice message reply failed',
            self::callback(static fn (array $context): bool => 'note.ogg' === $context['files'] && 'client gone' === $context['error']),
        );

        $reply = new VoiceMessageReply($this->ai(), $this->models(), $logger);

        $this->expectExceptionObject(new \RuntimeException('client gone'));
        $reply->reply($this->contextFailingOnComplete(), [['name' => 'note.ogg', 'type' => 'ogg', 'text' => 'Hello there.']]);
    }

    private function contextFailingOnComplete(): AnalysisContext
    {
        $message = $this->createStub(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn('');

        return AnalysisContext::for($message, ['language' => 'en'], static function (array $update): void {
            if ('complete' === $update['status']) {
                throw new \RuntimeException('client gone');
            }
        }, AnalysisReply::buffered());
    }

    private function ai(): AiFacade
    {
        $ai = $this->createStub(AiFacade::class);
        $ai->method('chat')->willReturn(['content' => 'Answer', 'provider' => 'openai', 'model' => 'gpt-4']);

        return $ai;
    }

    private function models(): ModelConfigService
    {
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getEffectiveUserIdForMessage')->willReturn(7);
        $models->method('getDefaultModel')->willReturn(12);
        $models->method('getProviderForModel')->willReturn('openai');
        $models->method('getModelName')->willReturn('gpt-4');

        return $models;
    }
}
