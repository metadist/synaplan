<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Entity\ComputeRun;
use App\Entity\User;
use App\Repository\ComputeRunRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\Contract\ComputeRunStatus;
use App\Service\Multitask\Execution\Runner\CodeRunRunner;
use App\Service\RateLimitService;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ComputeRunAuditTest extends TestCase
{
    public function testEveryDoorWritesRow(): void
    {
        foreach ([
            ComputeRun::VIA_PLANNER,
            ComputeRun::VIA_GATEWAY_ANTHROPIC,
            ComputeRun::VIA_GATEWAY_OPENAI,
            ComputeRun::VIA_SAVED_TASK,
        ] as $via) {
            $saved = [];
            $idsAtSave = [];
            $runs = $this->createMock(ComputeRunRepository::class);
            $runs->method('countActiveForUser')->willReturn(0);
            $runs->method('save')->willReturnCallback(static function (ComputeRun $run) use (&$saved, &$idsAtSave): void {
                $idsAtSave[] = $run->getArtefactIds();
                $saved[] = $run;
            });
            $this->runner($runs)->executeDirect(
                $this->user(),
                'python',
                'print(1)',
                [],
                10,
                $via,
            );
            self::assertNotEmpty($saved);
            self::assertSame($via, $saved[0]->getInvokedVia());
            self::assertNotEmpty($idsAtSave);
            self::assertNull($idsAtSave[0]);
        }
    }

    public function testNoScriptText(): void
    {
        $run = new ComputeRun(1, 'qabc', ComputeRun::VIA_PLANNER, 'python', 'python', ['timeoutSec' => 10]);
        $json = json_encode(get_object_vars($run), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('script', strtolower($json));
        self::assertStringNotContainsString('stdout', strtolower($json));
    }

    public function testAssistantForbidsWithoutAuditRow(): void
    {
        $saved = [];
        $runs = $this->createMock(ComputeRunRepository::class);
        $runs->method('save')->willReturnCallback(static function (ComputeRun $run) use (&$saved): void {
            $saved[] = $run;
        });
        $assistant = new RuntimeProfile(1, 'agent:demo', 'Hi', [], [], [], null, null, []);
        $result = $this->runner($runs)->executeDirect(
            $this->user(),
            'python',
            'print(1)',
            [],
            null,
            ComputeRun::VIA_PLANNER,
            $assistant,
        );

        self::assertSame('failed', $result['outcome']);
        self::assertSame(CodeRunRunner::FORBID_COPY, $result['error']);
        self::assertSame([], $saved);
    }

    private function runner(ComputeRunRepository $runs): CodeRunRunner
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('clampLimits')->willReturn([
            'timeoutSec' => 10,
            'memoryMb' => 512,
            'cpu' => 1.0,
            'pids' => 128,
            'outputMb' => 50,
        ]);
        $client = $this->createStub(ComputeClient::class);
        $client->method('submitRun')->willReturn('01ABCDEFGHJKLMNPQRSTUVWXYZ');
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ABCDEFGHJKLMNPQRSTUVWXYZ',
            status: 'succeeded',
            usage: ['wallMs' => 1, 'cpuSec' => 0.1, 'maxMemoryMb' => 10, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
        ));
        $client->method('collectLogs')->willReturn(['stdout' => '', 'stderr' => '']);
        $store = $this->createStub(ComputeArtefactStore::class);
        $store->method('ingestForUser')->willReturn([]);
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturn(['allowed' => true]);

        return new CodeRunRunner(
            $config,
            $client,
            $store,
            $runs,
            $this->createStub(FileRepository::class),
            $this->createStub(UserRepository::class),
            $limits,
            new NullLogger(),
            '/tmp',
        );
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('NEW');

        return $user;
    }
}
