<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\Message;
use App\Service\Connection\PlannerChannel;
use App\Service\File\ConnectedFolderContentGuard;
use App\Service\File\ConnectedFolderGuardCatalog;
use App\Service\File\ConnectedFolderReadHonestyPrompt;
use App\Service\Multitask\Execution\InputFileText;
use App\Service\PlatformLink\PickupService;
use App\Service\Connection\PlannerChannelCatalog;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class ConnectedFolderContentGuardTest extends TestCase
{
    /** @var list<PlannerChannel> */
    private array $folders;

    protected function setUp(): void
    {
        $this->folders = [
            new PlannerChannel('nextcloud', PlannerChannel::KIND_FOLDER, 'Office files', 12, ['folder_fetch', 'save_to_folder']),
        ];
    }

    public function testFolderContentAskWithoutBodiesGetsInstruction(): void
    {
        $prompt = $this->honestyPrompt(catalog: $this->folders);

        $instruction = $prompt->forChatHandler(
            $this->message('Build a table of vendor totals from the invoices in my Nextcloud Invoices folder.'),
            [],
        );

        self::assertStringContainsString('CONNECTED FOLDER READ HONESTY', $instruction);
    }

    public function testSummarizeFolderRequestIsDetected(): void
    {
        $guard = new ConnectedFolderContentGuard();

        self::assertTrue($guard->demandsFolderFileContent(
            'Summarize my Nextcloud folder',
            $this->folders,
        ));
    }

    public function testOrdinaryChatGetsNoInstruction(): void
    {
        $prompt = $this->honestyPrompt(catalog: $this->folders);

        self::assertSame('', $prompt->forChatHandler(
            $this->message('What is the weather in Berlin?'),
            [],
        ));
    }

    public function testSkipsWhenOnlyCurrentMessageHasFileText(): void
    {
        $file = $this->createMock(\App\Entity\File::class);
        $file->method('getFileText')->willReturn('Invoice Nordwind 1240 EUR');
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn('Summarize the invoices in Nextcloud.');
        $message->method('getUserId')->willReturn(1);
        $message->method('getFiles')->willReturn(new ArrayCollection([$file]));

        $prompt = $this->honestyPrompt(catalog: $this->folders);

        self::assertSame('', $prompt->forChatHandler($message, []));
    }

    public function testEarlierThreadAttachmentDoesNotDisableHonesty(): void
    {
        $oldFile = $this->createMock(\App\Entity\File::class);
        $oldFile->method('getFileText')->willReturn('My CV experience section');
        $oldMessage = $this->createMock(Message::class);
        $oldMessage->method('getFiles')->willReturn(new ArrayCollection([$oldFile]));

        $prompt = $this->honestyPrompt(catalog: $this->folders);

        $instruction = $prompt->forChatHandler(
            $this->message('List invoice totals from my Nextcloud folder.'),
            [$oldMessage],
        );

        self::assertStringContainsString('CONNECTED FOLDER READ HONESTY', $instruction);
    }

    public function testLinkedCloudHostCountsAsFolderSource(): void
    {
        $pickups = $this->createMock(PickupService::class);
        $pickups->method('readableLinks')->willReturn([
            [
                'identity_id' => 9,
                'channel' => 'owncloud',
                'host' => 'cloud.example.org',
                'client' => 'owncloud',
                'folders' => ['Invoices'],
            ],
        ]);
        $catalog = new ConnectedFolderGuardCatalog(
            $this->createMock(PlannerChannelCatalog::class),
            $pickups,
        );

        $prompt = $this->honestyPrompt(catalogService: $catalog);

        $instruction = $prompt->forChatHandler(
            $this->message('Show vendor totals from files on cloud.example.org'),
            [],
        );

        self::assertStringContainsString('CONNECTED FOLDER READ HONESTY', $instruction);
    }

    /**
     * @param list<PlannerChannel>|null $catalog
     */
    private function honestyPrompt(?array $catalog = null, ?ConnectedFolderGuardCatalog $catalogService = null): ConnectedFolderReadHonestyPrompt
    {
        if (null === $catalogService) {
            $planner = $this->createMock(PlannerChannelCatalog::class);
            $planner->method('ofKind')->willReturn($catalog ?? []);
            $pickups = $this->createMock(PickupService::class);
            $pickups->method('readableLinks')->willReturn([]);
            $catalogService = new ConnectedFolderGuardCatalog($planner, $pickups);
        }

        $inputFileText = $this->createMock(InputFileText::class);
        $inputFileText->method('of')->willReturn('');

        return new ConnectedFolderReadHonestyPrompt(
            new ConnectedFolderContentGuard(),
            $catalogService,
            $inputFileText,
        );
    }

    private function message(string $text): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getUserId')->willReturn(1);
        $message->method('getFiles')->willReturn(new ArrayCollection());

        return $message;
    }
}
