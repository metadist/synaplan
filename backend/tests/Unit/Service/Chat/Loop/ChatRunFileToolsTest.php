<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\AI\Messages\Tools\AnalyzeImageTool;
use App\Entity\Chat;
use App\Entity\File;
use App\Entity\User;
use App\Repository\ChatFileRepository;
use App\Service\Chat\Loop\ChatRunFileTools;
use App\Service\Chat\Loop\ChatRunScope;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\File\ConversationFileCatalog;
use App\Service\Project\ProjectTurnContext;
use PHPUnit\Framework\TestCase;

/**
 * Files of the chat by ID (§0 #28): every file is listed, a file outside the
 * chat never resolves, and a picture reaches the model only as text.
 */
final class ChatRunFileToolsTest extends TestCase
{
    private const USER = 7;
    private const CHAT = 11;

    public function testListShowsEveryFileWithItsIdAndNeverItsData(): void
    {
        $files = $this->createMock(ChatFileRepository::class);
        $files->method('total')->willReturn(23);
        $files->expects(self::once())->method('page')->with(self::USER, self::CHAT, 'project:abc', 20, 20)->willReturn([
            $this->file(4711, 'floorplan.png', 'chat/floorplan.png', 'web_upload', "Floor plan\nof the kitchen"),
            $this->file(4712, 'brief.txt', 'project/brief.txt', 'project_text', str_repeat('word ', 60)),
            $this->file(4713, 'logo.png', 'chat/logo.png', 'generated', ''),
        ]);

        $result = $this->tools($files)->run($this->scope('project:abc'), $this->state(), ChatRunFileTools::LIST_FILES, ['page' => 2]);

        self::assertStringStartsWith('Files of this chat (page 2 of 2, 23 in all):', $result->text);
        self::assertStringContainsString('- file_id 4711 · floorplan.png · image · uploaded · ', $result->text);
        self::assertStringContainsString('Floor plan of the kitchen', $result->text);
        self::assertStringContainsString('file_id 4712 · brief.txt · document · project source', $result->text);
        self::assertStringContainsString('file_id 4713 · logo.png · image · made in this chat', $result->text);
        self::assertStringContainsString('…', $result->text, 'a long description is cut');
    }

    public function testAPageBeyondTheLastShowsTheLast(): void
    {
        $files = $this->createMock(ChatFileRepository::class);
        $files->method('total')->willReturn(5);
        $files->expects(self::once())->method('page')->with(self::USER, self::CHAT, null, 0, 20)->willReturn([]);

        $this->tools($files)->run($this->scope(), $this->state(), ChatRunFileTools::LIST_FILES, ['page' => 9]);
    }

    public function testAPageSentAsAStringIsRead(): void
    {
        $files = $this->createMock(ChatFileRepository::class);
        $files->method('total')->willReturn(45);
        $files->expects(self::once())->method('page')->with(self::USER, self::CHAT, null, 40, 20)->willReturn([]);

        $this->tools($files)->run($this->scope(), $this->state(), ChatRunFileTools::LIST_FILES, ['page' => '3']);
    }

    public function testAChatWithoutFilesSaysSo(): void
    {
        $result = $this->tools($this->createStub(ChatFileRepository::class))->run($this->scope(), $this->state(), ChatRunFileTools::LIST_FILES, []);

        self::assertSame('This chat has no files.', $result->text);
    }

    public function testALongDocumentIsReadInParts(): void
    {
        $text = str_repeat('a', 8_000).str_repeat('b', 2_000);
        $files = $this->createStub(ChatFileRepository::class);
        $files->method('findInChat')->willReturn($this->file(5, 'report.pdf', 'chat/report.pdf', 'web_upload', $text));
        $tools = $this->tools($files);

        $first = $tools->run($this->scope(), $this->state(), ChatRunFileTools::READ_DOCUMENT, ['file_id' => 5]);
        $second = $tools->run($this->scope(), $this->state(), ChatRunFileTools::READ_DOCUMENT, ['file_id' => '5', 'offset' => 8_000]);

        self::assertStringContainsString('Characters 0–8000 of 10000. Pass offset 8000 to read on.', $first->text);
        self::assertStringNotContainsString('b', substr($first->text, -100));
        self::assertStringContainsString('Characters 8000–10000 of 10000.', $second->text);
        self::assertStringNotContainsString('Pass offset', $second->text);
        self::assertStringEndsWith(str_repeat('b', 2_000), $second->text);
    }

    public function testAFileOutsideTheChatIsNotRead(): void
    {
        $files = $this->createMock(ChatFileRepository::class);
        $files->expects(self::once())->method('findInChat')->with(self::USER, self::CHAT, 'project:abc', 99)->willReturn(null);
        $tools = $this->tools($files);

        $result = $tools->run($this->scope('project:abc'), $this->state(), ChatRunFileTools::READ_DOCUMENT, ['file_id' => 99]);
        $invented = $tools->run($this->scope('project:abc'), $this->state(), ChatRunFileTools::READ_DOCUMENT, ['file_id' => '../etc/passwd']);

        self::assertTrue($result->failed);
        self::assertSame(ChatRunFileTools::NOT_IN_CHAT, $result->text);
        self::assertSame(ChatRunFileTools::NOT_IN_CHAT, $invented->text, 'a file_id that is not a number never reaches the database');
    }

    public function testAFileWithoutTextPointsToTheVisionTool(): void
    {
        $files = $this->createStub(ChatFileRepository::class);
        $files->method('findInChat')->willReturn($this->file(6, 'photo.jpg', 'chat/photo.jpg', 'web_upload', ''));

        $result = $this->tools($files)->run($this->scope(), $this->state(), ChatRunFileTools::READ_DOCUMENT, ['file_id' => 6]);

        self::assertSame(ChatRunFileTools::NO_TEXT, $result->text);
    }

    public function testAGeneratedReportWithoutExtractedTextIsReadFromDisk(): void
    {
        $dir = sys_get_temp_dir().'/chat-run-file-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/report.md', "# Report\n\nThe kitchen costs 12,404 euros.");
        $files = $this->createStub(ChatFileRepository::class);
        $files->method('findInChat')->willReturn($this->file(50, 'report.md', 'research/report.md', 'generated', ''));
        $catalog = $this->createStub(ConversationFileCatalog::class);
        $catalog->method('absolutePath')->willReturn($dir.'/report.md');

        try {
            $result = (new ChatRunFileTools($files, $catalog, $this->createStub(AnalyzeImageTool::class)))
                ->run($this->scope(), $this->state(), ChatRunFileTools::READ_DOCUMENT, ['file_id' => 50]);
        } finally {
            unlink($dir.'/report.md');
            rmdir($dir);
        }

        self::assertStringContainsString('The kitchen costs 12,404 euros.', $result->text);
    }

    public function testAPictureIsLookedAtInPlaceAndComesBackAsText(): void
    {
        $files = $this->createStub(ChatFileRepository::class);
        $files->method('findInChat')->willReturn($this->file(4711, 'floorplan.png', 'chat/floorplan.png', 'web_upload', ''));
        $vision = $this->createMock(AnalyzeImageTool::class);
        $vision->expects(self::once())->method('analyseStored')->with('chat/floorplan.png', 'Where is the window?', self::USER, 'de')
            ->willReturn(['text' => 'The window is on the left wall.', 'isError' => false, 'summary' => 'image']);
        $state = $this->state();

        $result = $this->tools($files, $vision)->run($this->scope(), $state, ChatRunFileTools::ANALYZE_IMAGE, ['file_id' => 4711, 'prompt' => 'Where is the window?']);

        self::assertSame("Picture: floorplan.png\nfile_id: 4711\n\nThe window is on the left wall.", $result->text);
        self::assertSame([4711], $state->analyzed);
    }

    public function testTheVisionCallCountsAgainstTheRunsCost(): void
    {
        $files = $this->createStub(ChatFileRepository::class);
        $files->method('findInChat')->willReturnCallback(fn (int $u, int $c, ?string $g, int $id): File => $this->file($id, $id.'.png', 'chat/'.$id.'.png', 'web_upload', ''));
        $vision = $this->createStub(AnalyzeImageTool::class);
        $vision->method('analyseStored')->willReturnOnConsecutiveCalls(
            ['text' => 'seen', 'isError' => false, 'summary' => 'image', 'chargedCost' => 0.0042],
            ['text' => 'Vision model returned an empty description.', 'isError' => true, 'summary' => 'error', 'chargedCost' => 0.001],
            ['text' => 'No vision model.', 'isError' => true, 'summary' => 'error'],
        );
        $tools = $this->tools($files, $vision);
        $state = $this->state();
        $state->costMicro = 10_000;

        $tools->run($this->scope(), $state, ChatRunFileTools::ANALYZE_IMAGE, ['file_id' => 1]);
        $failed = $tools->run($this->scope(), $state, ChatRunFileTools::ANALYZE_IMAGE, ['file_id' => 2]);
        $tools->run($this->scope(), $state, ChatRunFileTools::ANALYZE_IMAGE, ['file_id' => 3]);

        self::assertTrue($failed->failed);
        self::assertSame(10_000 + 4_200 + 1_000, $state->costMicro, 'a billed call counts even when it failed; an unbilled one adds nothing');
    }

    public function testOnlyPicturesGoToTheVisionModel(): void
    {
        $files = $this->createStub(ChatFileRepository::class);
        $files->method('findInChat')->willReturn($this->file(5, 'report.pdf', 'chat/report.pdf', 'web_upload', 'text'));
        $vision = $this->createMock(AnalyzeImageTool::class);
        $vision->expects(self::never())->method('analyseStored');

        $result = $this->tools($files, $vision)->run($this->scope(), $this->state(), ChatRunFileTools::ANALYZE_IMAGE, ['file_id' => 5]);

        self::assertSame(ChatRunFileTools::NOT_A_PICTURE, $result->text);
    }

    public function testARunLooksAtAFewPicturesAtMost(): void
    {
        $files = $this->createStub(ChatFileRepository::class);
        $files->method('findInChat')->willReturnCallback(fn (int $u, int $c, ?string $g, int $id): File => $this->file($id, $id.'.png', 'chat/'.$id.'.png', 'web_upload', ''));
        $vision = $this->createMock(AnalyzeImageTool::class);
        $vision->expects(self::exactly(ChatRunFileTools::MAX_PICTURES + 1))->method('analyseStored')
            ->willReturn(['text' => 'seen', 'isError' => false, 'summary' => 'image']);
        $tools = $this->tools($files, $vision);
        $state = $this->state();

        for ($id = 1; $id <= ChatRunFileTools::MAX_PICTURES; ++$id) {
            $tools->run($this->scope(), $state, ChatRunFileTools::ANALYZE_IMAGE, ['file_id' => $id]);
        }
        $again = $tools->run($this->scope(), $state, ChatRunFileTools::ANALYZE_IMAGE, ['file_id' => 1, 'prompt' => 'the text only']);
        $more = $tools->run($this->scope(), $state, ChatRunFileTools::ANALYZE_IMAGE, ['file_id' => 99]);

        self::assertFalse($again->failed, 'a picture already looked at may be looked at again');
        self::assertSame(ChatRunFileTools::PICTURES_USED, $more->text);
        self::assertCount(ChatRunFileTools::MAX_PICTURES, $state->analyzed);
    }

    public function testAPictureThatIsNoLongerStoredIsNotSent(): void
    {
        $files = $this->createStub(ChatFileRepository::class);
        $files->method('findInChat')->willReturn($this->file(8, 'gone.png', 'chat/gone.png', 'web_upload', ''));
        $vision = $this->createMock(AnalyzeImageTool::class);
        $vision->expects(self::never())->method('analyseStored');
        $state = $this->state();

        $result = $this->tools($files, $vision, onDisk: false)->run($this->scope(), $state, ChatRunFileTools::ANALYZE_IMAGE, ['file_id' => 8]);

        self::assertSame(ChatRunFileTools::MISSING, $result->text);
        self::assertSame([], $state->analyzed);
    }

    public function testTheVisionToolIsOfferedOnlyWithAVisionModel(): void
    {
        $vision = $this->createStub(AnalyzeImageTool::class);
        $vision->method('isAvailable')->willReturn(true);
        $names = static fn (array $d): array => array_map(static fn (array $x): string => (string) $x['function']['name'], $d);

        self::assertSame(['list_files', 'read_document', 'analyze_image'], $names($this->tools($this->createStub(ChatFileRepository::class), $vision)->declarations($this->scope())));
        self::assertSame(['list_files', 'read_document'], $names($this->tools($this->createStub(ChatFileRepository::class))->declarations($this->scope())));
    }

    private function tools(ChatFileRepository $files, ?AnalyzeImageTool $vision = null, bool $onDisk = true): ChatRunFileTools
    {
        $catalog = $this->createStub(ConversationFileCatalog::class);
        $catalog->method('normalizeRelativePath')->willReturnArgument(0);
        $catalog->method('absolutePath')->willReturn($onDisk ? '/var/uploads/x' : null);

        return new ChatRunFileTools($files, $catalog, $vision ?? $this->createStub(AnalyzeImageTool::class));
    }

    private function file(int $id, string $name, string $path, string $source, string $text): File
    {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getFileName')->willReturn($name);
        $file->method('getFilePath')->willReturn($path);
        $file->method('getSource')->willReturn($source);
        $file->method('getFileText')->willReturn($text);
        $file->method('getCreatedAt')->willReturn(1_795_000_000);

        return $file;
    }

    private function scope(?string $groupKey = null): ChatRunScope
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::USER);
        $chat = $this->createStub(Chat::class);
        $chat->method('getId')->willReturn(self::CHAT);

        return new ChatRunScope($user, $chat, null === $groupKey ? null : new ProjectTurnContext($groupKey, 'Kitchen', null));
    }

    private function state(): ChatRunState
    {
        return new ChatRunState('01JD6Q3Z8Y9X7W6V5T4S3R2Q1P', self::USER, self::CHAT, null, null, null, 'de', '', [], 1);
    }
}
