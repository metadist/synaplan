<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Service\Destination\RequestedFolderDelivery;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\SaveToFolderRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SaveToFolderRunnerTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/save_folder_'.uniqid();
        mkdir($this->uploadDir.'/1/000', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->uploadDir.'/1/000', $this->uploadDir.'/1', $this->uploadDir] as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    public function testUploadsUpstreamFileAndStreamsConfirmation(): void
    {
        $png = $this->uploadDir.'/1/000/cat.png';
        file_put_contents($png, 'image');

        $delivery = $this->createMock(RequestedFolderDelivery::class);
        $delivery->expects(self::once())
            ->method('send')
            ->with(7, [['path' => realpath($png), 'name' => 'cat.png']], 'nextcloud')
            ->willReturn([
                'ok' => true,
                'message' => 'Saved the file to nextcloud (Synaplan/cat.png).',
                'sent' => 1,
                'connection' => 'nextcloud-Ordner (admin)',
                'channel' => 'nextcloud',
            ]);

        $ctx = $this->context();
        $ctx->setResult('n1', NodeResult::ok(null, [[
            'path' => '/api/v1/files/uploads/1/000/cat.png',
            'type' => 'image',
            'local_path' => '1/000/cat.png',
        ]]));

        $chunks = [];
        $ctx->setChunkSink(function (string $nodeId, string $chunk) use (&$chunks): void {
            $chunks[] = [$nodeId, $chunk];
        });
        $ctx->beginNode('n2');

        $result = $this->runner($delivery)->run($this->node(), $ctx);

        self::assertTrue($result->isSuccessful());
        self::assertSame(1, $result->metadata['files_saved']);
        self::assertSame('nextcloud', $result->metadata['folder_channel']);
        self::assertSame([['n2', 'Saved the file to nextcloud (Synaplan/cat.png).']], $chunks);
    }

    public function testFailsWhenDeliveryReportsNoConnection(): void
    {
        $delivery = $this->createMock(RequestedFolderDelivery::class);
        $delivery->method('send')->willReturn([
            'ok' => false,
            'message' => 'no folder is connected — add one under Settings → Connections',
            'sent' => 0,
                'connection' => null,
                'channel' => null,
            ]);

        $result = $this->runner($delivery)->run($this->node(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('no folder is connected', (string) $result->error);
    }

    public function testDescribesSaveToFolderOnly(): void
    {
        $delivery = $this->createMock(RequestedFolderDelivery::class);
        $descriptors = $this->runner($delivery)->describe();

        self::assertCount(1, $descriptors);
        self::assertSame(Capability::SaveToFolder, $descriptors[0]->capability);
        self::assertTrue($descriptors[0]->requiresDynamicNote);
    }

    private function runner(RequestedFolderDelivery $delivery): SaveToFolderRunner
    {
        return new SaveToFolderRunner(
            $delivery,
            $this->createMock(LoggerInterface::class),
            $this->uploadDir,
        );
    }

    private function context(): NodeContext
    {
        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn('erstelle das bild einer katze und lege es in meinen nextcloud account');
        $m->method('getFileText')->willReturn('');
        $m->method('getLanguage')->willReturn('de');
        $m->method('getFile')->willReturn(0);
        $m->method('getFilePath')->willReturn('');
        $m->method('getFiles')->willReturn(new ArrayCollection());
        $m->method('getUserId')->willReturn(7);

        return new NodeContext($m, [], 7, ['language' => 'de']);
    }

    private function node(): TaskNode
    {
        return new TaskNode('n2', Capability::SaveToFolder, ['n1'], [
            'attachments' => ['$n1.file'],
        ], ['channel' => 'nextcloud']);
    }
}
