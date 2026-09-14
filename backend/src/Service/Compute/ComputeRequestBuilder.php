<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\Service\Compute\Contract\ComputeRunRequest;

/**
 * Builds protocol-1 run requests. PHP never puts a host path in the payload.
 */
final readonly class ComputeRequestBuilder
{
    public const PROTOCOL = 1;

    /**
     * @param array{kind: string, id?: string}                                            $workspace
     * @param array{program: string, args: list<string>}                                  $entry
     * @param list<array{name: string, role: string}>                                     $files
     * @param array{timeoutSec: int, memoryMb: int, cpu: float, pids: int, outputMb: int} $limits
     * @param array{allow: list<array{host: string, port: int, ips: list<string>}>}       $egress
     */
    public function build(
        string $owner,
        array $workspace,
        string $image,
        array $entry,
        array $files,
        array $limits,
        array $egress,
    ): ComputeRunRequest {
        if ('' === $owner) {
            throw new ComputeRefusedException('missing_owner', 'owner is required');
        }

        return new ComputeRunRequest(
            protocol: self::PROTOCOL,
            owner: $owner,
            workspace: $workspace,
            image: $image,
            entry: $entry,
            files: $files,
            limits: $limits,
            egress: $egress,
        );
    }

    /**
     * @param array{program: string, args: list<string>}                                  $entry
     * @param list<array{name: string, role: string}>                                     $files
     * @param array{timeoutSec: int, memoryMb: int, cpu: float, pids: int, outputMb: int} $limits
     * @param array{allow: list<array{host: string, port: int, ips: list<string>}>}       $egress
     */
    public function ephemeralRun(
        string $owner,
        string $image,
        array $entry,
        array $files,
        array $limits,
        array $egress = ['allow' => []],
    ): ComputeRunRequest {
        return $this->build($owner, ['kind' => 'run'], $image, $entry, $files, $limits, $egress);
    }

    /**
     * @param array{program: string, args: list<string>}                                  $entry
     * @param list<array{name: string, role: string}>                                     $files
     * @param array{timeoutSec: int, memoryMb: int, cpu: float, pids: int, outputMb: int} $limits
     * @param array{allow: list<array{host: string, port: int, ips: list<string>}>}       $egress
     */
    public function userWorkspaceRun(
        string $owner,
        string $workspaceId,
        string $image,
        array $entry,
        array $files,
        array $limits,
        array $egress = ['allow' => []],
    ): ComputeRunRequest {
        if ('' === $workspaceId) {
            throw new ComputeRefusedException('workspace_not_found', 'workspace id is required');
        }

        return $this->build(
            $owner,
            ['kind' => 'user', 'id' => $workspaceId],
            $image,
            $entry,
            $files,
            $limits,
            $egress,
        );
    }
}
