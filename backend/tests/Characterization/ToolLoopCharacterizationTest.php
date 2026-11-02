<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Tests\Characterization\Support\ToolLoopHarness;
use App\Tests\Characterization\Support\ToolLoopSnapshot;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of every tool loop in Synaplan (S4 AU23).
 *
 * Scripted model turns drive ChatToolLoop, GatewayToolLoop (complete and
 * stream), OpenAiGatewayToolLoop (complete and stream), the Saved Task
 * runners, approval resume, and Synaplan's own MCP server. Each case records
 * the tools that ran, the gate decision or "no gate", approval rows, audit
 * rows, usage rows, the clamped result and the stop reason.
 *
 * Recorded on the untouched code. The known gaps (document tools and MCP
 * reads without a gate, no audit row for an executed tool, no usage row on
 * the OpenAI loop) are in the snapshot on purpose.
 *
 * Record with UPDATE_TOOL_LOOP_SNAPSHOTS=1.
 */
#[AllowMockObjectsWithoutExpectations]
final class ToolLoopCharacterizationTest extends TestCase
{
    private const SNAPSHOT = __DIR__.'/__snapshots__/tool_loops.json';

    /** @var list<string> */
    private const EXPECTED = [
        'chat.document_edit.approvals_off',
        'chat.document_edit.approvals_on',
        'chat.document_edit.clamp',
        'gateway_complete.client_tool',
        'gateway_complete.code.approvals_off',
        'gateway_complete.code.approvals_on',
        'gateway_complete.custom_http.approvals_off',
        'gateway_complete.custom_http.approvals_on',
        'gateway_complete.image.approvals_off',
        'gateway_complete.image.approvals_on',
        'gateway_complete.iterations',
        'gateway_complete.mcp_read.approvals_off',
        'gateway_complete.mcp_read.approvals_on',
        'gateway_complete.mcp_read.clamp',
        'gateway_complete.mcp_write.approvals_off',
        'gateway_complete.mcp_write.approvals_on',
        'gateway_complete.mix',
        'gateway_complete.web_search.approvals_off',
        'gateway_complete.web_search.approvals_on',
        'gateway_stream.client_tool',
        'gateway_stream.code.approvals_off',
        'gateway_stream.custom_http.approvals_off',
        'gateway_stream.image.approvals_off',
        'gateway_stream.mcp_read.approvals_off',
        'gateway_stream.mcp_read.approvals_on',
        'gateway_stream.mcp_write.approvals_off',
        'gateway_stream.mcp_write.approvals_on',
        'gateway_stream.mix',
        'gateway_stream.web_search.approvals_off',
        'mcp_server.file_ingest',
        'mcp_server.memory_add',
        'openai_complete.client_tool',
        'openai_complete.code.approvals_off',
        'openai_complete.code.approvals_on',
        'openai_complete.custom_http',
        'openai_complete.image.approvals_off',
        'openai_complete.image.approvals_on',
        'openai_complete.iterations',
        'openai_complete.mcp_read.approvals_off',
        'openai_complete.mcp_read.approvals_on',
        'openai_complete.mcp_write.approvals_off',
        'openai_complete.mcp_write.approvals_on',
        'openai_complete.mix',
        'openai_complete.web_search.approvals_off',
        'openai_complete.web_search.approvals_on',
        'openai_stream.client_tool',
        'openai_stream.mcp_read.approvals_off',
        'openai_stream.mcp_read.approvals_on',
        'openai_stream.mcp_write.approvals_on',
        'openai_stream.mix',
        'openai_stream.web_search.approvals_off',
        'resume_approval.custom_http',
        'resume_approval.mcp_write',
        'runner_code.approvals_off',
        'runner_code.approvals_on_interactive',
        'runner_code.approvals_on_unattended',
        'runner_mcp_action.write.already_approved',
        'runner_mcp_action.write.approvals_off',
        'runner_mcp_action.write.approvals_on_interactive',
        'runner_mcp_action.write.approvals_on_unattended',
        'runner_mcp_fetch.read.approvals_off',
        'runner_mcp_fetch.read.approvals_on',
        'runner_tool_call.custom.already_approved',
        'runner_tool_call.custom.approvals_off',
        'runner_tool_call.custom.approvals_on',
        'runner_tool_call.custom.approvals_on_unattended',
        'runner_tool_call.mcp_write.approvals_on',
    ];

    public function testEveryToolLoopMatchesTheSnapshot(): void
    {
        $actual = (new ToolLoopHarness(fn (string $class): object => $this->createMock($class)))->runAll();
        $keys = array_keys($actual['cases']);
        sort($keys);

        self::assertSame(self::EXPECTED, $keys);
        ToolLoopSnapshot::assertSame(self::SNAPSHOT, $actual, 'tool loops');
    }
}
