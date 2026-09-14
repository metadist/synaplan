<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\AI\Messages\Tools\CodeExecutionTool;
use App\Service\Multitask\Plan\Capability;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolSourceInterface;

final readonly class ComputeToolSource implements ToolSourceInterface
{
    public function __construct(
        private ComputeConfig $computeConfig,
    ) {
    }

    public function source(): ToolSource
    {
        return ToolSource::Compute;
    }

    public function describe(int $userId, array $context = []): array
    {
        if (!$this->computeConfig->isEnabled($userId > 0 ? $userId : null)) {
            return [];
        }

        return [
            new ToolDescriptor(
                name: Capability::CodeRun->value,
                title: 'File work',
                description: 'Run a short Python or Node script on copies of files the user already owns and return the created files.',
                inputSchema: [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['language', 'code'],
                    'properties' => [
                        'language' => ['type' => 'string', 'enum' => ['python', 'node']],
                        'code' => ['type' => 'string', 'maxLength' => CodeExecutionTool::MAX_CODE_CHARS],
                        'input_file_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'maxItems' => CodeExecutionTool::MAX_INPUT_FILES,
                        ],
                        'timeout_sec' => [
                            'type' => 'integer',
                            'minimum' => CodeExecutionTool::MIN_TIMEOUT_SEC,
                            'maximum' => CodeExecutionTool::MAX_TIMEOUT_SEC,
                        ],
                    ],
                ],
                sideEffect: SideEffect::Write,
                source: ToolSource::Compute,
                ownerId: 0,
                meta: [
                    'available' => true,
                    'gatewayName' => CodeExecutionTool::NAME,
                ],
            ),
        ];
    }
}
