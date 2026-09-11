<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Service\SavedTask\Graph\SavedTaskGraphValidator;
use App\Service\SavedTask\WorkflowsConfig;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;

final class SavedTaskGraphValidatorTest extends TestCase
{
    private SavedTaskGraphValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SavedTaskGraphValidator();
    }

    public function testRejectsTriggerMismatch(): void
    {
        $errors = $this->validator->validate(
            [
                'version' => 1,
                'trigger' => ['id' => 't1', 'type' => 'schedule'],
                'nodes' => [['id' => 'n1', 'capability' => 'chat', 'depends_on' => []]],
            ],
            'manual',
            null,
        );

        $this->assertNotEmpty($errors);
    }

    public function testRejectsCycle(): void
    {
        $errors = $this->validator->validate(
            [
                'version' => 1,
                'trigger' => ['id' => 't1', 'type' => 'manual'],
                'nodes' => [
                    ['id' => 'n1', 'capability' => 'chat', 'depends_on' => ['n2']],
                    ['id' => 'n2', 'capability' => 'email_search', 'depends_on' => ['n1']],
                ],
            ],
            'manual',
            null,
        );

        $this->assertContains('steps contain a cycle', $errors);
    }

    public function testAcceptsFlagshipGraph(): void
    {
        $errors = $this->validator->validate(
            [
                'version' => 1,
                'trigger' => ['id' => 't1', 'type' => 'schedule'],
                'nodes' => [
                    ['id' => 'n1', 'capability' => 'email_search', 'depends_on' => []],
                    ['id' => 'n2', 'capability' => 'chat', 'depends_on' => ['n1']],
                    ['id' => 'n3', 'capability' => 'calendar_event', 'depends_on' => ['n2']],
                ],
            ],
            'schedule',
            ['kind' => 'weekly'],
        );

        $this->assertSame([], $errors);
    }

    public function testFlagOffRejectsBuilderOnlyCapabilitiesAsUnknown(): void
    {
        $errors = $this->validator->validate(
            [
                'version' => 1,
                'trigger' => ['type' => 'manual'],
                'nodes' => [['id' => 'n1', 'capability' => 'tool_call', 'depends_on' => [], 'params' => ['tool' => 'custom:x']]],
            ],
            'manual',
            null,
        );

        $this->assertContains('step[0] has an unknown action', $errors);
    }

    public function testFlagOnAcceptsBuilderCapabilitiesAndRejectsAutoOverride(): void
    {
        $config = $this->createMock(WorkflowsConfig::class);
        $config->method('isBuilderEnabled')->willReturn(true);
        $ssrf = $this->createMock(SsrfGuard::class);
        $ssrf->method('isBlockedUrl')->willReturn(false);
        $validator = new SavedTaskGraphValidator($config, $ssrf);

        $ok = $validator->validate(
            [
                'version' => 1,
                'trigger' => ['type' => 'webhook'],
                'nodes' => [
                    [
                        'id' => 'n1',
                        'capability' => 'tool_call',
                        'depends_on' => [],
                        'params' => ['tool' => 'custom:x', 'approval' => 'approve'],
                    ],
                    [
                        'id' => 'n2',
                        'capability' => 'outbound_webhook',
                        'depends_on' => ['n1'],
                        'params' => [
                            'url' => 'https://example.com/hook',
                            'inputs' => ['text' => ['from' => 'n1', 'field' => 'text']],
                        ],
                    ],
                ],
            ],
            'webhook',
            ['token' => 't'],
        );
        $this->assertSame([], $ok);

        $auto = $validator->validate(
            [
                'version' => 1,
                'trigger' => ['type' => 'manual'],
                'nodes' => [[
                    'id' => 'n1',
                    'capability' => 'tool_call',
                    'depends_on' => [],
                    'params' => ['tool' => 'custom:x', 'approval' => 'auto'],
                ]],
            ],
            'manual',
            null,
        );
        $this->assertContains('step[0] can only tighten approval (ask me, or block)', $auto);

        // Only the tool gate pauses a run; "ask me" on a webhook step would be an empty promise.
        $askOnWebhook = $validator->validate(
            [
                'version' => 1,
                'trigger' => ['type' => 'manual'],
                'nodes' => [[
                    'id' => 'n1',
                    'capability' => 'outbound_webhook',
                    'depends_on' => [],
                    'params' => ['url' => 'https://example.com/hook', 'approval' => 'approve'],
                ]],
            ],
            'manual',
            null,
        );
        $this->assertContains('step[0] can only ask before a tool step', $askOnWebhook);

        $from = $validator->validate(
            [
                'version' => 1,
                'trigger' => ['type' => 'manual'],
                'nodes' => [
                    ['id' => 'n1', 'capability' => 'chat', 'depends_on' => []],
                    [
                        'id' => 'n2',
                        'capability' => 'condition',
                        'depends_on' => [],
                        'params' => [
                            'operator' => 'equals',
                            'inputs' => ['input' => ['from' => 'n1', 'field' => 'text']],
                        ],
                    ],
                ],
            ],
            'manual',
            null,
        );
        $this->assertContains("step[1] input 'input' must come from an earlier step this step depends on", $from);
    }
}
