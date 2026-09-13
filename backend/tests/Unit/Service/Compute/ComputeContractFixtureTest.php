<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Service\Compute\Contract\ComputeArtefact;
use App\Service\Compute\Contract\ComputeErrorBody;
use App\Service\Compute\Contract\ComputeHealth;
use App\Service\Compute\Contract\ComputeRunRequest;
use App\Service\Compute\Contract\ComputeRunStatus;
use App\Service\Compute\Contract\ComputeWorkspaceCreate;
use App\Service\Compute\Contract\ComputeWorkspaceUsage;
use PHPUnit\Framework\TestCase;

/**
 * C7: vendored protocol-1 fixtures stay byte-identical and decode.
 */
final class ComputeContractFixtureTest extends TestCase
{
    private const DIR = __DIR__.'/../../../Fixtures/compute-contract';

    public function testChecksumsMatchCommittedLedger(): void
    {
        $ledger = file(self::DIR.'/CHECKSUMS.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($ledger);
        $this->assertNotEmpty($ledger);

        foreach ($ledger as $line) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  \S+$/', $line);
            [$hash, $name] = explode('  ', $line, 2);
            $path = self::DIR.'/'.$name;
            $this->assertFileExists($path, $name);
            $this->assertSame($hash, hash_file('sha256', $path), $name);
        }
    }

    public function testJsonFixturesDecodeIntoProtocolDtos(): void
    {
        $map = [
            'run_request_python.json' => ComputeRunRequest::fromJson(...),
            'run_request_node.json' => ComputeRunRequest::fromJson(...),
            'run_request_user_workspace.json' => ComputeRunRequest::fromJson(...),
            'run_request_egress.json' => ComputeRunRequest::fromJson(...),
            'run_status_succeeded.json' => ComputeRunStatus::fromJson(...),
            'run_status_timeout.json' => ComputeRunStatus::fromJson(...),
            'run_status_cancelled.json' => ComputeRunStatus::fromJson(...),
            'run_status_output_limit.json' => ComputeRunStatus::fromJson(...),
            'health.json' => ComputeHealth::fromJson(...),
            'artefact_list.json' => ComputeArtefact::listFromJson(...),
            'workspace_create.json' => ComputeWorkspaceCreate::fromJson(...),
            'workspace_usage.json' => ComputeWorkspaceUsage::fromJson(...),
            'error_limits_exceed_caps.json' => ComputeErrorBody::fromJson(...),
            'error_workspace_quota_exceeded.json' => ComputeErrorBody::fromJson(...),
            'error_egress_not_allowed.json' => ComputeErrorBody::fromJson(...),
            'error_invalid_json.json' => ComputeErrorBody::fromJson(...),
            'error_payload_too_large.json' => ComputeErrorBody::fromJson(...),
        ];

        foreach ($map as $name => $decode) {
            $json = file_get_contents(self::DIR.'/'.$name);
            $this->assertNotFalse($json, $name);
            $decoded = $decode($json);
            $this->assertNotNull($decoded, $name);
        }

        $python = ComputeRunRequest::fromJson((string) file_get_contents(self::DIR.'/run_request_python.json'));
        $this->assertSame(1, $python->protocol);
        $this->assertSame('user:123', $python->owner);
        $this->assertSame('python', $python->image);
        $this->assertSame('python', $python->entry['program']);
    }

    public function testLogsSseIsNotJsonAndStaysByteLocked(): void
    {
        $raw = file_get_contents(self::DIR.'/logs.sse');
        $this->assertNotFalse($raw);
        $this->assertStringContainsString('event: stdout', $raw);
        $this->assertFalse(json_validate($raw));
    }
}
