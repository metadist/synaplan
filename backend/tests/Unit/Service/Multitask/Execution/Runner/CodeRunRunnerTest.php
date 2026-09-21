<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ComputeRunRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeEgressResolver;
use App\Service\Compute\ComputeRefusedException;
use App\Service\Compute\ComputeWorkspaceService;
use App\Service\Compute\Contract\ComputeRunRequest;
use App\Service\Compute\Contract\ComputeRunStatus;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\CodeRunRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\RateLimitService;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CodeRunRunnerTest extends TestCase
{
    public function testRejectsForeignFileIds(): void
    {
        $foreign = $this->createStub(File::class);
        $foreign->method('getUserId')->willReturn(99);
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturn($foreign);

        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->never())->method('submitRun');

        $result = $this->runner($client, $files)->run(
            new TaskNode('n1', Capability::CodeRun, params: [
                'script' => 'print(1)',
                'inputFileIds' => [55],
            ]),
            $this->context(),
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('A selected file is not yours. Nothing new was saved.', $result->error);
    }

    public function testClampsLimitsToCaps(): void
    {
        $captured = null;
        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->once())
            ->method('submitRun')
            ->willReturnCallback(static function (ComputeRunRequest $request) use (&$captured): string {
                $captured = $request;

                return '01ARZ3NDEKTSV4RRFFQ69G5FAV';
            });
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'succeeded',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
            durationMs: 10,
        ));
        $client->method('collectLogs')->willReturn(['stdout' => '', 'stderr' => '']);
        $artefacts = $this->createStub(ComputeArtefactStore::class);
        $artefacts->method('ingest')->willReturn([]);

        $this->runner($client, $this->createStub(FileRepository::class), $artefacts)->run(
            new TaskNode('n1', Capability::CodeRun, params: [
                'script' => 'print(1)',
                'limits' => [
                    'timeoutSec' => 999,
                    'memoryMb' => 9999,
                    'cpu' => 8.0,
                    'pids' => 9999,
                    'outputMb' => 999,
                ],
            ]),
            $this->context(),
        );

        $this->assertInstanceOf(ComputeRunRequest::class, $captured);
        $this->assertSame(60, $captured->limits['timeoutSec']);
        $this->assertSame(512, $captured->limits['memoryMb']);
        $this->assertSame(1.0, $captured->limits['cpu']);
        $this->assertSame(128, $captured->limits['pids']);
        $this->assertSame(50, $captured->limits['outputMb']);
        $this->assertSame(['_synaplan_main.py'], $captured->entry['args']);
    }

    public function testMissingInputFileFailsTheRun(): void
    {
        $missing = $this->createStub(File::class);
        $missing->method('getUserId')->willReturn(7);
        $missing->method('getFileName')->willReturn('notes.txt');
        $missing->method('getFilePath')->willReturn('/tmp/does-not-exist-'.bin2hex(random_bytes(4)));
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturn($missing);
        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->never())->method('submitRun');

        $result = $this->runner($client, $files)->run(
            new TaskNode('n1', Capability::CodeRun, params: [
                'script' => 'print(1)',
                'inputFileIds' => [12],
            ]),
            $this->context(),
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('A selected file could not be read. Nothing new was saved.', $result->error);
    }

    public function testDuplicateInputNamesStayDistinct(): void
    {
        $dir = sys_get_temp_dir().'/compute-input-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/a.csv', 'a');
        file_put_contents($dir.'/b.csv', 'b');
        $first = $this->file(7, 'data.csv', $dir.'/a.csv');
        $second = $this->file(7, 'data.csv', $dir.'/b.csv');
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturnOnConsecutiveCalls($first, $second);
        $captured = [];
        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->once())
            ->method('submitRun')
            ->willReturnCallback(static function (ComputeRunRequest $request, iterable $parts) use (&$captured): string {
                $captured = ['request' => $request, 'names' => []];
                foreach ($parts as $part) {
                    $captured['names'][] = $part['name'];
                }

                return '01ARZ3NDEKTSV4RRFFQ69G5FAV';
            });
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'succeeded',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
            durationMs: 10,
        ));
        $client->method('collectLogs')->willReturn(['stdout' => '', 'stderr' => '']);
        $artefacts = $this->createStub(ComputeArtefactStore::class);
        $artefacts->method('ingest')->willReturn([]);

        $this->runner($client, $files, $artefacts)->run(
            new TaskNode('n1', Capability::CodeRun, params: [
                'script' => 'print(1)',
                'inputFileIds' => [1, 2],
            ]),
            $this->context(),
        );

        $this->assertSame(['data.csv', 'data_2.csv', '_synaplan_main.py'], $captured['names']);
        unlink($dir.'/a.csv');
        unlink($dir.'/b.csv');
        rmdir($dir);
    }

    public function testWorkspaceOnlyWhenFlagOn(): void
    {
        $captured = null;
        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->once())
            ->method('submitRun')
            ->willReturnCallback(static function (ComputeRunRequest $request) use (&$captured): string {
                $captured = $request;

                return '01ARZ3NDEKTSV4RRFFQ69G5FAV';
            });
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'succeeded',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
            durationMs: 10,
        ));
        $client->method('collectLogs')->willReturn(['stdout' => '', 'stderr' => '']);

        $this->runner($client, $this->createStub(FileRepository::class))->run(
            new TaskNode('n1', Capability::CodeRun, params: [
                'script' => 'print(1)',
                'useWorkspace' => true,
            ]),
            $this->context(),
        );

        $this->assertInstanceOf(ComputeRunRequest::class, $captured);
        $this->assertSame(['kind' => 'run'], $captured->workspace);

        $workspace = $this->createStub(\App\Entity\ComputeWorkspace::class);
        $workspace->method('getWorkspaceId')->willReturn('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $workspaces = $this->createMock(ComputeWorkspaceService::class);
        $workspaces->expects($this->once())->method('ensure')->willReturn($workspace);
        $workspaces->method('forUser')->willReturn($workspace);

        $capturedOn = null;
        $clientOn = $this->createMock(ComputeClient::class);
        $clientOn->expects($this->once())
            ->method('submitRun')
            ->willReturnCallback(static function (ComputeRunRequest $request) use (&$capturedOn): string {
                $capturedOn = $request;

                return '01ARZ3NDEKTSV4RRFFQ69G5FAV';
            });
        $clientOn->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'succeeded',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
            durationMs: 10,
        ));
        $clientOn->method('collectLogs')->willReturn(['stdout' => '', 'stderr' => '']);

        $this->runner($clientOn, $this->createStub(FileRepository::class), null, true, $workspaces)->run(
            new TaskNode('n1', Capability::CodeRun, params: [
                'script' => 'print(1)',
                'useWorkspace' => true,
            ]),
            $this->context(),
        );

        $this->assertInstanceOf(ComputeRunRequest::class, $capturedOn);
        $this->assertSame(['kind' => 'user', 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], $capturedOn->workspace);
    }

    public function testEgressWithoutApprovalsFailsClosed(): void
    {
        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->never())->method('submitRun');

        // Egress on, EGRESS_REQUIRES_APPROVAL at its default (on), no execution
        // gate wired: nothing can produce an approval, so the run must refuse
        // instead of reaching the website unattended.
        $result = $this->runner($client, $this->createStub(FileRepository::class), egressOn: true)->run(
            new TaskNode('n1', Capability::CodeRun, params: [
                'script' => 'print(1)',
                'egressHosts' => ['8.8.8.8'],
            ]),
            $this->context(),
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(CodeRunRunner::EGRESS_NEEDS_APPROVALS_COPY, $result->error);
    }

    public function testEgressOffIgnoresHostsAndStaysOffline(): void
    {
        $captured = null;
        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->once())
            ->method('submitRun')
            ->willReturnCallback(static function (ComputeRunRequest $request) use (&$captured): string {
                $captured = $request;

                return '01ARZ3NDEKTSV4RRFFQ69G5FAV';
            });
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'succeeded',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
            durationMs: 10,
        ));
        $client->method('collectLogs')->willReturn(['stdout' => '', 'stderr' => '']);

        $this->runner($client, $this->createStub(FileRepository::class))->run(
            new TaskNode('n1', Capability::CodeRun, params: [
                'script' => 'print(1)',
                'egressHosts' => ['8.8.8.8'],
            ]),
            $this->context(),
        );

        $this->assertInstanceOf(ComputeRunRequest::class, $captured);
        $this->assertSame(['allow' => []], $captured->egress);
    }

    public function testSuccessfulRunSurfacesStdoutAsReply(): void
    {
        $client = $this->createMock(ComputeClient::class);
        $client->method('submitRun')->willReturn('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'succeeded',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 2],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
            durationMs: 10,
        ));
        $client->method('collectLogs')->willReturn(['stdout' => "2\n", 'stderr' => '']);

        $result = $this->runner($client, $this->createStub(FileRepository::class))->run(
            new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(2)']),
            $this->context(),
        );

        $this->assertTrue($result->isSuccessful());
        // The script's stdout IS the answer — not a generic "File work finished".
        $this->assertSame('2', $result->text);
    }

    public function testEgressUnavailableGetsItsOwnSentence(): void
    {
        $client = $this->createMock(ComputeClient::class);
        $client->method('submitRun')->willReturn('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'failed',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
            reason: 'egress_unavailable',
            durationMs: 10,
        ));
        $client->method('collectLogs')->willReturn(['stdout' => '', 'stderr' => '']);

        $result = $this->runner($client, $this->createStub(FileRepository::class))->run(
            new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(1)']),
            $this->context(),
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertStringStartsWith('File work could not reach the approved websites', (string) $result->error);
    }

    public function testWorkspaceRefusalsGetTheirOwnSentences(): void
    {
        foreach ([
            'workspace_busy' => 'Another file-work run is still using your folder.',
            'workspace_quota_exceeded' => 'Your file-work folder is full.',
        ] as $code => $starts) {
            $client = $this->createMock(ComputeClient::class);
            $client->method('submitRun')->willThrowException(new ComputeRefusedException($code, 'sidecar says no', httpStatus: 409));

            $result = $this->runner($client, $this->createStub(FileRepository::class))->run(
                new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(1)']),
                $this->context(),
            );

            $this->assertFalse($result->isSuccessful());
            $this->assertStringStartsWith($starts, (string) $result->error, $code);
            $this->assertStringNotContainsString("week's file-work limit", (string) $result->error, $code);
        }
    }

    public function testRunRolledBackForFolderQuotaSaysWhatWasRemoved(): void
    {
        $client = $this->createMock(ComputeClient::class);
        $client->method('submitRun')->willReturn('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'failed',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
            reason: 'workspace_quota_exceeded',
            durationMs: 10,
        ));
        $client->method('collectLogs')->willReturn(['stdout' => '', 'stderr' => '']);

        $result = $this->runner($client, $this->createStub(FileRepository::class))->run(
            new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(1)']),
            $this->context(),
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertStringStartsWith('This run wrote more than your file-work folder allows.', (string) $result->error);
        $this->assertStringContainsString('Files that were already there stay', (string) $result->error);
    }

    public function testTimeoutAfterUsingTheFolderDoesNotClaimNothingWasSaved(): void
    {
        $workspace = $this->createStub(\App\Entity\ComputeWorkspace::class);
        $workspace->method('getWorkspaceId')->willReturn('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $workspaces = $this->createMock(ComputeWorkspaceService::class);
        $workspaces->method('ensure')->willReturn($workspace);

        $client = $this->createMock(ComputeClient::class);
        $client->method('submitRun')->willReturn('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'failed',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: -1,
            reason: 'timeout',
            durationMs: 10,
        ));
        $client->method('collectLogs')->willReturn(['stdout' => '', 'stderr' => '']);

        $result = $this->runner($client, $this->createStub(FileRepository::class), null, true, $workspaces)->run(
            new TaskNode('n1', Capability::CodeRun, params: [
                'script' => 'print(1)',
                'useWorkspace' => true,
            ]),
            $this->context(),
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertTrue($result->metadata['used_workspace'] ?? false);
        $this->assertStringContainsString('open Workspace to check', (string) $result->error);
        $this->assertStringNotContainsString('Nothing new was saved', (string) $result->error);
    }

    /**
     * Issue #2052: a failed run must surface the one-sentence outcome only.
     * The interpreter output stays in the server log, never in user copy.
     */
    public function testFailedRunKeepsStderrOutOfTheUserMessage(): void
    {
        $client = $this->createMock(ComputeClient::class);
        $client->method('submitRun')->willReturn('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'failed',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 1,
            reason: 'program_error',
            durationMs: 10,
        ));
        $client->method('collectLogs')->willReturn([
            'stdout' => '',
            'stderr' => "Traceback (most recent call last):\n  File \"/work/_synaplan_main.py\", line 11, in <module>\nKeyError: 'order_amount'",
        ]);

        $result = $this->runner($client, $this->createStub(FileRepository::class))->run(
            new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(1)']),
            $this->context(),
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('File work could not finish. Nothing new was saved.', $result->error);
    }

    /**
     * Issue #2048: the Saved-line must describe what the artefacts actually
     * contain (read back from the stored files), not what the script printed.
     */
    public function testSuccessfulRunDescribesArtefactsFromReadback(): void
    {
        $dir = '/tmp/coderun_readback_'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        try {
            file_put_contents($dir.'/cleaned.csv', "Date,Amount,Note\n2026-01-02,800.00,OK\n2026-01-03,3000.25,ok\n");
            file_put_contents($dir.'/chart.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

            $csv = $this->storedFile(101, 'cleaned.csv', 'text/csv', $dir.'/cleaned.csv');
            $png = $this->storedFile(102, 'chart.png', 'image/png', $dir.'/chart.png');

            $client = $this->createMock(ComputeClient::class);
            $client->method('submitRun')->willReturn('01ARZ3NDEKTSV4RRFFQ69G5FAV');
            $client->method('status')->willReturn(new ComputeRunStatus(
                runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                status: 'succeeded',
                usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 2],
                truncated: ['stdout' => false, 'stderr' => false],
                exitCode: 0,
                durationMs: 10,
            ));
            $client->method('collectLogs')->willReturn(['stdout' => "done\n", 'stderr' => '']);

            $artefacts = $this->createStub(ComputeArtefactStore::class);
            $artefacts->method('ingest')->willReturn([$csv, $png]);

            $files = $this->createMock(FileRepository::class);
            $files->method('find')->willReturnCallback(
                static fn (int $id): ?File => 101 === $id ? $csv : (102 === $id ? $png : null),
            );

            $result = $this->runner($client, $files, $artefacts)->run(
                new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print("done")']),
                $this->context(),
            );

            $this->assertTrue($result->isSuccessful());
            $this->assertStringContainsString(
                'Saved 2 file(s): cleaned.csv (CSV, 2 rows × 3 columns; headers: Date, Amount, Note); chart.png (PNG image, 1x1,',
                (string) $result->text,
            );
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    public function testUnreadableArtefactSaysSoHonestly(): void
    {
        $dir = '/tmp/coderun_readback_'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        try {
            // Not a PNG despite the name — getimagesize fails.
            file_put_contents($dir.'/broken.png', 'this is not image data');

            $png = $this->storedFile(103, 'broken.png', 'image/png', $dir.'/broken.png');

            $client = $this->createMock(ComputeClient::class);
            $client->method('submitRun')->willReturn('01ARZ3NDEKTSV4RRFFQ69G5FAV');
            $client->method('status')->willReturn(new ComputeRunStatus(
                runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                status: 'succeeded',
                usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 2],
                truncated: ['stdout' => false, 'stderr' => false],
                exitCode: 0,
                durationMs: 10,
            ));
            $client->method('collectLogs')->willReturn(['stdout' => '', 'stderr' => '']);

            $artefacts = $this->createStub(ComputeArtefactStore::class);
            $artefacts->method('ingest')->willReturn([$png]);

            $files = $this->createMock(FileRepository::class);
            $files->method('find')->willReturn($png);

            $result = $this->runner($client, $files, $artefacts)->run(
                new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(1)']),
                $this->context(),
            );

            $this->assertTrue($result->isSuccessful());
            $this->assertStringContainsString('broken.png (could not be read back,', (string) $result->text);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    public function testEmptyArtefactIsFlaggedAsEmpty(): void
    {
        $empty = $this->createStub(File::class);
        $empty->method('getId')->willReturn(104);
        $empty->method('getFileName')->willReturn('empty.csv');
        $empty->method('getFileMime')->willReturn('text/csv');
        $empty->method('getFileSize')->willReturn(0);

        $client = $this->createMock(ComputeClient::class);
        $client->method('submitRun')->willReturn('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'succeeded',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 2],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
            durationMs: 10,
        ));
        $client->method('collectLogs')->willReturn(['stdout' => '', 'stderr' => '']);

        $artefacts = $this->createStub(ComputeArtefactStore::class);
        $artefacts->method('ingest')->willReturn([$empty]);

        $result = $this->runner($client, $this->createStub(FileRepository::class), $artefacts)->run(
            new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(1)']),
            $this->context(),
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertStringContainsString('Saved 1 file(s): empty.csv (empty).', (string) $result->text);
    }

    /**
     * Issue #1875 / PR #1949: missing COMPUTE_CONCURRENT / COMPUTE_CPU_SECONDS_DAILY
     * rows must fall back using the resolved group tier, not the billing level.
     */
    public function testMissingComputeCapsUseResolvedGroupTier(): void
    {
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('resolveRateLimitLevel')->willReturn('BUSINESS');
        $limits->method('checkLimit')->willReturn(['allowed' => true]);
        $limits->method('computeIntSetting')->willReturnCallback(
            static fn (User $_user, string $_setting, int $fallback): int => $fallback,
        );

        $runner = $this->runner(
            $this->createStub(ComputeClient::class),
            $this->createStub(FileRepository::class),
            limits: $limits,
        );

        $user = $this->createStub(User::class);
        $user->method('getRateLimitLevel')->willReturn('NEW');

        $concurrent = (new \ReflectionMethod(CodeRunRunner::class, 'defaultConcurrentCap'))->invoke($runner, $user);
        $cpu = (new \ReflectionMethod(CodeRunRunner::class, 'defaultCpuCap'))->invoke($runner, $user);

        self::assertSame(4, $concurrent);
        self::assertSame(3600, $cpu);
    }

    private function file(int $userId, string $name, string $path): File
    {
        $file = $this->createStub(File::class);
        $file->method('getUserId')->willReturn($userId);
        $file->method('getFileName')->willReturn($name);
        $file->method('getFilePath')->willReturn($path);

        return $file;
    }

    private function storedFile(int $id, string $name, string $mime, string $absolutePath): File
    {
        // The test runner wires uploadDir to /tmp, so stored paths are relative to it.
        $relative = ltrim(substr($absolutePath, strlen('/tmp')), '/');
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getUserId')->willReturn(7);
        $file->method('getFileName')->willReturn($name);
        $file->method('getFileMime')->willReturn($mime);
        $file->method('getFileSize')->willReturn((int) filesize($absolutePath));
        $file->method('getFilePath')->willReturn($relative);

        return $file;
    }

    private function runner(
        ComputeClient $client,
        FileRepository $files,
        ?ComputeArtefactStore $artefacts = null,
        bool $workspacesOn = false,
        ?ComputeWorkspaceService $workspaces = null,
        bool $egressOn = false,
        ?RateLimitService $limits = null,
    ): CodeRunRunner {
        $repo = $this->createStub(\App\Repository\ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static function (int $_owner, string $_group, string $setting) use ($workspacesOn, $egressOn): ?string {
                return match ($setting) {
                    'ENABLED' => '1',
                    'WORKSPACES_ENABLED' => $workspacesOn ? '1' : '0',
                    'EGRESS_ENABLED' => $egressOn ? '1' : '0',
                    default => null,
                };
            },
        );
        $config = new ComputeConfig($repo, 'http://compute:8080', 'token-token-token-token-token-32b');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('NEW');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);

        if (null === $limits) {
            $limits = $this->createStub(RateLimitService::class);
            $limits->method('checkLimit')->willReturn(['allowed' => true]);
            $limits->method('computeIntSetting')->willReturnCallback(
                static fn (User $_user, string $setting, int $fallback): int => match ($setting) {
                    'COMPUTE_CONCURRENT' => 2,
                    'COMPUTE_CPU_SECONDS_DAILY' => 60,
                    default => $fallback,
                },
            );
        }

        $runs = $this->createStub(ComputeRunRepository::class);
        $runs->method('countActiveForUser')->willReturn(0);
        $runs->method('sumDurationMsSince')->willReturn(0);

        return new CodeRunRunner(
            $config,
            $client,
            $artefacts ?? $this->createStub(ComputeArtefactStore::class),
            $runs,
            $files,
            $users,
            $limits,
            new NullLogger(),
            '/tmp',
            null,
            $workspaces,
            new ComputeEgressResolver($config, new SsrfGuard()),
        );
    }

    private function context(): NodeContext
    {
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn(11);

        return new NodeContext($message, [], 7, []);
    }
}
