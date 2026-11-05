<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\Handler\FileAnalysisHandler;
use App\Service\ModelConfigService;
use App\Tests\Support\FileAnalysisHandlerFactory;
use App\Tests\Support\FixedAuthorizationChecker;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * A screenshot with no typed text must not become "describe this image"
 * when the thread already has a question. That is the injected
 * "Please review the attached file." failure: the previous how-to was
 * overwritten by a file-analysis caption.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class FileAnalysisHandlerImageContextTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private FileAnalysisHandler $handler;
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $modelConfig = $this->createMock(ModelConfigService::class);
        $this->uploadDir = sys_get_temp_dir().'/synaplan-image-context-'.bin2hex(random_bytes(8));
        mkdir($this->uploadDir, 0o775, true);

        $this->handler = FileAnalysisHandlerFactory::create(
            $this->aiFacade,
            $modelConfig,
            $this->createMock(LoggerInterface::class),
            $this->uploadDir,
            null,
            null,
            new ChatFailureClassifier(),
            new ChatErrorPresenter(new IdentityTranslator(), new ChatFailureClassifier(), new FixedAuthorizationChecker(false)),
        );

        $modelConfig->method('getEffectiveUserIdForMessage')->willReturn(7);
        $modelConfig->method('getDefaultModel')->willReturn(123);
        $modelConfig->method('getProviderForModel')->willReturn('openai');
        $modelConfig->method('getModelName')->willReturn('gpt-4o');
    }

    protected function tearDown(): void
    {
        $full = $this->uploadDir.'/13/000/shot.png';
        if (is_file($full)) {
            unlink($full);
        }
        if (is_dir($this->uploadDir.'/13/000')) {
            rmdir($this->uploadDir.'/13/000');
        }
        if (is_dir($this->uploadDir.'/13')) {
            rmdir($this->uploadDir.'/13');
        }
        if (is_dir($this->uploadDir)) {
            rmdir($this->uploadDir);
        }
    }

    public function testAFirstTurnImageWithoutTextAsksForADescription(): void
    {
        $prompt = $this->visionPromptFor('', []);

        $this->assertStringContainsString('Please describe this image in detail.', $prompt);
        $this->assertStringNotContainsString('evidence for the conversation', $prompt);
    }

    public function testAnImageInALiveThreadKeepsThePreviousQuestion(): void
    {
        $prior = (new Message())->setUserId(7)->setDirection('IN')->setText('Wie verbinde ich Outlook?');
        $prompt = $this->visionPromptFor('', [$prior]);

        $this->assertStringContainsString('evidence for the conversation so far', $prompt);
        $this->assertStringContainsString('Do not describe or summarise the image', $prompt);
        $this->assertStringNotContainsString('Please describe this image in detail.', $prompt);
    }

    public function testTheInjectedReviewSentenceIsTreatedAsEmpty(): void
    {
        $prior = (new Message())->setUserId(7)->setDirection('OUT')->setText('Open Apps, then Microsoft 365.');
        $prompt = $this->visionPromptFor('Please review the attached file.', [$prior]);

        $this->assertStringContainsString('evidence for the conversation so far', $prompt);
        $this->assertStringNotContainsString('Please review the attached file.', $prompt);
    }

    public function testATypedQuestionAboutTheImageIsKept(): void
    {
        $prior = (new Message())->setUserId(7)->setDirection('IN')->setText('Wie verbinde ich Outlook?');
        $prompt = $this->visionPromptFor('What is on this photo?', [$prior]);

        $this->assertSame('What is on this photo?', $prompt);
    }

    /**
     * @param list<Message> $thread
     */
    private function visionPromptFor(string $text, array $thread): string
    {
        $this->putImageOnDisk();
        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('analyzeImage')
            ->willReturnCallback(function (string $path, string $prompt) use (&$captured): array {
                $captured = $prompt;

                return ['content' => 'ok', 'provider' => 'openai', 'model' => 'gpt-4o'];
            });

        $this->handler->handle($this->imageMessage($text), $thread, ['language' => 'en']);

        $this->assertNotNull($captured);
        $directiveAt = strpos($captured, "\n\nLanguage:");

        return false === $directiveAt ? $captured : substr($captured, 0, $directiveAt);
    }

    private function imageMessage(string $text): Message&MockObject
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(11);
        $file->method('getFileName')->willReturn('shot.png');
        $file->method('getFileType')->willReturn('png');
        $file->method('getFilePath')->willReturn('13/000/shot.png');
        $file->method('getFileText')->willReturn('');
        $file->method('getStatus')->willReturn('processed');

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(42);
        $message->method('getUserId')->willReturn(7);
        $message->method('getFiles')->willReturn(new ArrayCollection([$file]));
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('en');

        return $message;
    }

    private function putImageOnDisk(): void
    {
        $dir = $this->uploadDir.'/13/000';
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create test upload directory.');
        }
        file_put_contents($dir.'/shot.png', 'fake-image-bytes');
    }
}
