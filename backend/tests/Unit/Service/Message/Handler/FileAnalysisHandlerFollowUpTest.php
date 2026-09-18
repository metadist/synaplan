<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Service\File\ConversationFileCatalog;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\Handler\FileAnalysisHandler;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Follow-up turns must keep analyzing the document that is already in the
 * conversation. The handler used to look only at the current message and
 * answer "No file was provided" after two or three text-only questions.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class FileAnalysisHandlerFollowUpTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private FileAnalysisHandler $handler;
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->uploadDir = sys_get_temp_dir().'/synaplan-file-followup-'.bin2hex(random_bytes(8));
        mkdir($this->uploadDir, 0o775, true);

        $this->handler = new FileAnalysisHandler(
            $this->aiFacade,
            $this->modelConfigService,
            $this->createMock(LoggerInterface::class),
            $this->uploadDir,
            null,
            null,
            new ChatFailureClassifier(),
            new ChatErrorPresenter(new IdentityTranslator(), new ChatFailureClassifier()),
        );

        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(123);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->uploadDir);
    }

    public function testFollowUpWithoutAttachmentUsesThreadDocument(): void
    {
        $prior = (new Message())->setUserId(7)->setDirection('IN')->addFile(
            $this->document(11, 'contract.pdf', 'Clause 4: notice period is three months.'),
        );
        $followUp = (new Message())->setUserId(7)->setChatId(42)->setText('Check the whole contract for adjustments.')->setLanguage('en');

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$captured) {
                $captured = $messages;

                return ['content' => 'Clause 4 needs a review.', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $result = $this->handler->handle($followUp, [$prior], ['language' => 'en']);

        $this->assertSame('Clause 4 needs a review.', $result['content']);
        $this->assertSame('contract.pdf', $result['metadata']['analyzed_file']);
        $this->assertIsArray($captured);
        $this->assertStringContainsString('Clause 4: notice period is three months.', $captured[0]['content']);
        $this->assertSame('Check the whole contract for adjustments.', $captured[1]['content']);
    }

    public function testStreamingFollowUpWithoutAttachmentUsesThreadDocument(): void
    {
        $prior = (new Message())->setUserId(7)->setDirection('IN')->addFile(
            $this->document(11, 'contract.pdf', 'The salary is paid monthly.'),
        );
        $followUp = (new Message())->setUserId(7)->setText('What is the salary clause?')->setLanguage('en');

        $this->aiFacade
            ->expects($this->once())
            ->method('chatStream')
            ->willReturnCallback(function (array $messages, callable $cb) {
                $this->assertStringContainsString('The salary is paid monthly.', $messages[0]['content']);
                $cb('Paid monthly.');

                return ['provider' => 'openai', 'model' => 'gpt-4'];
            });

        $streamed = '';
        $result = $this->handler->handleStream($followUp, [$prior], ['language' => 'en'], function (string $chunk) use (&$streamed): void {
            $streamed .= $chunk;
        });

        $this->assertSame('Paid monthly.', $streamed);
        $this->assertSame('contract.pdf', $result['metadata']['analyzed_file']);
    }

    public function testCurrentAttachmentStillWinsOverThreadDocument(): void
    {
        $prior = (new Message())->setUserId(7)->addFile(
            $this->document(11, 'old.pdf', 'Old contract text.'),
        );
        $current = (new Message())->setUserId(7)->setText('Summarize.')->setLanguage('en')->addFile(
            $this->document(22, 'new.pdf', 'New contract text.'),
        );

        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) {
                $this->assertStringContainsString('New contract text.', $messages[0]['content']);
                $this->assertStringNotContainsString('Old contract text.', $messages[0]['content']);

                return ['content' => 'New one.', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $result = $this->handler->handle($current, [$prior], []);

        $this->assertSame('new.pdf', $result['metadata']['analyzed_file']);
    }

    public function testStillReportsNoFileWhenTheConversationHasNone(): void
    {
        $followUp = (new Message())->setUserId(7)->setText('Check the contract.')->setLanguage('en');

        $this->aiFacade->expects($this->never())->method('chat');

        $result = $this->handler->handle($followUp, [], []);

        $this->assertSame('no_file', $result['metadata']['error']);
        $this->assertStringContainsString('No file was provided', $result['content']);
    }

    public function testCatalogSuppliesDocumentWhenHistoryEntitiesLostTheRelation(): void
    {
        $pdf = $this->document(77, 'brief.pdf', 'Clause 1: pay on Friday.');
        $repository = $this->createMock(FileRepository::class);
        $repository->method('findFilesByMessageIds')->willReturn([]);
        $repository->method('findFilesByChatId')->willReturn([$pdf]);
        $repository->method('findOneBy')->willReturn(null);

        $handler = new FileAnalysisHandler(
            $this->aiFacade,
            $this->modelConfigService,
            $this->createMock(LoggerInterface::class),
            $this->uploadDir,
            null,
            null,
            new ChatFailureClassifier(),
            new ChatErrorPresenter(new IdentityTranslator(), new ChatFailureClassifier()),
            null,
            new ConversationFileCatalog($repository, $this->uploadDir),
        );

        $followUp = (new Message())->setUserId(7)->setChatId(42)->setText('What does the brief say?')->setLanguage('en');

        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) {
                $this->assertStringContainsString('Clause 1: pay on Friday.', $messages[0]['content']);

                return ['content' => 'Pay on Friday.', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $result = $handler->handle($followUp, [], []);

        $this->assertSame('brief.pdf', $result['metadata']['analyzed_file']);
    }

    private function document(int $id, string $name, string $text): File
    {
        file_put_contents($this->uploadDir.'/'.$name, 'pdf-bytes');

        $file = (new File())
            ->setUserId(7)
            ->setFilePath($name)
            ->setFileType('pdf')
            ->setFileName($name)
            ->setFileSize(9)
            ->setFileMime('application/pdf')
            ->setFileText($text)
            ->setStatus('processed');
        (new \ReflectionProperty(File::class, 'id'))->setValue($file, $id);

        return $file;
    }
}
