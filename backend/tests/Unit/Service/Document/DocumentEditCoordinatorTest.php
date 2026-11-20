<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Document;

use App\Entity\DocumentRevision;
use App\Entity\Message;
use App\Entity\Model;
use App\Repository\FileRepository;
use App\Service\Document\ChatToolLoop;
use App\Service\Document\ChatToolLoopResult;
use App\Service\Document\DocumentEditCoordinator;
use App\Service\Document\DocumentKind;
use App\Service\Document\DocumentToolsConfig;
use App\Service\Document\Import\DocumentImporter;
use App\Service\Document\Model\DeckModel;
use App\Service\Document\Model\SpreadsheetModel;
use App\Service\Document\Model\WordBlock;
use App\Service\Document\Model\WordModel;
use App\Service\Document\Persist\DocumentRevisionService;
use App\Service\Document\Persist\DocumentTextProjector;
use App\Service\Document\Render\DocumentRenderer;
use App\Service\Document\Serializer\DocumentModelSerializer;
use App\Service\Document\Tool\DocumentSession;
use App\Service\Document\Tool\DocumentToolResult;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DocumentEditCoordinatorTest extends TestCase
{
    public function testShouldRunRequiresOfficemakerEnabledFlagAndToolUse(): void
    {
        $config = $this->createMock(DocumentToolsConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $coordinator = $this->coordinator($config);

        $message = new Message();
        $message->setUserId(1);
        $model = $this->createMock(Model::class);
        $model->expects(self::any())->method('hasFeature')->with('tool_use')->willReturn(true);

        self::assertTrue($coordinator->shouldRun('officemaker', $model, $message));
        self::assertFalse($coordinator->shouldRun('general', $model, $message));
        self::assertFalse($coordinator->shouldRun('officemaker', null, $message));
    }

    public function testShouldRunIsFalseWhenFlagOff(): void
    {
        $config = $this->createMock(DocumentToolsConfig::class);
        $config->method('isEnabled')->willReturn(false);
        $coordinator = $this->coordinator($config);
        $message = new Message();
        $message->setUserId(1);
        $model = $this->createMock(Model::class);
        $model->expects(self::any())->method('hasFeature')->with('tool_use')->willReturn(true);

        self::assertFalse($coordinator->shouldRun('officemaker', $model, $message));
    }

    /**
     * The live failure: tools inserted the German title and stopped. The
     * request already held the whole plan, and that plan has to be the file.
     */
    public function testRunWritesTheProvidedBodyWhenToolsKeptOnlyTheTitle(): void
    {
        $plan = <<<'MD'
            # Reiseplan: Rio de Janeiro – Nova Odessa – Copacabana

            ## Überblick

            - **Start:** Flug nach Rio de Janeiro am 12.
            - **Transport:** Mietwagen von Rio nach Nova Odessa und zurück
            - **Rückfahrt:** Ein Sightseeing-Stopp
            - **Abschluss:** 2–3 Nächte in einem Luxushotel an der Copacabana

            Die Dauer des Aufenthalts in Nova Odessa ist hier nicht festgelegt.

            ## Tag 1 (der 12.): Ankunft in Rio

            - Landung am internationalen Flughafen Rio de Janeiro–Galeão (GIG).
            MD;
        $prompt = "Create a Word document (DOCX) containing the following German text:\n".$plan;

        $captured = null;
        $renderer = $this->createMock(DocumentRenderer::class);
        $renderer->expects(self::once())->method('render')->willReturnCallback(function (SpreadsheetModel|WordModel|DeckModel $model, string $path) use (&$captured): string {
            self::assertInstanceOf(WordModel::class, $model);
            $captured = $model;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, 'docx');

            return 'docx';
        });

        $loop = $this->createMock(ChatToolLoop::class);
        $loop->expects(self::once())->method('run')->willReturnCallback(function (array $messages, DocumentSession $session, array $options = [], ?int $userId = null, ?callable $onStep = null): ChatToolLoopResult {
            unset($messages, $options, $userId, $onStep);
            $word = $session->word();
            self::assertInstanceOf(WordModel::class, $word);
            $word->blocks[] = new WordBlock('only-title', WordBlock::TYPE_HEADING, [
                'text' => 'Reiseplan: Rio de Janeiro – Nova Odessa – Copacabana',
                'level' => 1,
            ]);
            $session->record(DocumentToolResult::ok('Inserted only-title', 'processing.documentStepInsertBlock'));

            return new ChatToolLoopResult('short summary', $session);
        });

        $revisions = $this->createMock(DocumentRevisionService::class);
        $revisions->expects(self::once())->method('append')->willReturn((new DocumentRevision())->setVersion(1));

        $coordinator = new DocumentEditCoordinator(
            $this->createStub(DocumentToolsConfig::class),
            $loop,
            $revisions,
            $this->createStub(DocumentImporter::class),
            $this->createStub(DocumentModelSerializer::class),
            $renderer,
            new DocumentTextProjector(),
            $this->createStub(FileRepository::class),
            new UserUploadPathBuilder(),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            sys_get_temp_dir(),
        );

        $message = new Message();
        $message->setUserId(2);
        $message->setText($prompt);

        $result = $coordinator->run(
            [
                ['role' => 'system', 'content' => 'tools'],
                ['role' => 'user', 'content' => $prompt."\n\n\n---\n\n\nUser provided 1 file(s):\n\nHuge reference material that must stay out of the document."],
            ],
            $message,
            ['document_body' => $prompt],
        );

        self::assertNotNull($result);
        self::assertInstanceOf(WordModel::class, $captured);
        $text = (new DocumentTextProjector())->project($captured);
        self::assertStringContainsString('Überblick', $text);
        self::assertStringContainsString('Flug nach Rio de Janeiro am 12.', $text);
        self::assertStringContainsString('Landung am internationalen Flughafen', $text);
        self::assertStringNotContainsString('**', $text);
        self::assertStringNotContainsString('Huge reference', $text);
    }

    public function testCasualPowerPointRequestStartsADeck(): void
    {
        $config = $this->createMock(DocumentToolsConfig::class);
        $coordinator = $this->coordinator($config);
        $method = new \ReflectionMethod(DocumentEditCoordinator::class, 'inferKind');

        self::assertSame(
            DocumentKind::PPTX,
            $method->invoke($coordinator, 'kannst du mir eine power point präsentation erstellen, nur ganz kurz'),
        );
        self::assertSame(DocumentKind::XLSX, $method->invoke($coordinator, 'Vergleich als Excel'));
        self::assertSame(DocumentKind::DOCX, $method->invoke($coordinator, 'mach ein Dokument dazu'));
        self::assertSame(DocumentKind::PPTX, $method->invoke($coordinator, 'mach ein paar Slides dazu'));
        self::assertNull($method->invoke($coordinator, 'erzähl mir was über Synaplan'));
    }

    private function coordinator(DocumentToolsConfig $config): DocumentEditCoordinator
    {
        return new DocumentEditCoordinator(
            $config,
            $this->createMock(ChatToolLoop::class),
            $this->createMock(DocumentRevisionService::class),
            $this->createMock(DocumentImporter::class),
            $this->createMock(DocumentModelSerializer::class),
            $this->createMock(DocumentRenderer::class),
            $this->createMock(DocumentTextProjector::class),
            $this->createMock(FileRepository::class),
            $this->createMock(UserUploadPathBuilder::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            sys_get_temp_dir(),
        );
    }
}
