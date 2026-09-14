<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\Entity\User;
use App\Service\Compute\ComputeConfig;
use App\Service\Runtime\RuntimeProfile;

/**
 * Synaplan file-work tool for both `/v1` gateways. Replaces an upstream
 * `code_execution_*` server tool when the key grants `compute:run`.
 */
final readonly class CodeExecutionTool
{
    public const NAME = 'code_execution';
    public const MAX_CODE_CHARS = 65536;
    public const MAX_INPUT_FILES = 10;
    public const MIN_TIMEOUT_SEC = 1;
    public const MAX_TIMEOUT_SEC = 300;

    public function __construct(
        private CodeExecutionInvoker $invoker,
        private ComputeConfig $computeConfig,
    ) {
    }

    public function isAvailable(?int $userId = null): bool
    {
        return $this->computeConfig->isEnabled($userId);
    }

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function declaration(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Run a short Python or Node script on copies of files the user already owns and return the created files. Use it for spreadsheets, charts, conversions and other file work. Do not use it for general questions.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['language', 'code'],
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'enum' => ['python', 'node'],
                        'description' => 'Language of the script.',
                    ],
                    'code' => [
                        'type' => 'string',
                        'maxLength' => self::MAX_CODE_CHARS,
                        'description' => 'The script to run. Write output files under /out.',
                    ],
                    'input_file_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'maxItems' => self::MAX_INPUT_FILES,
                        'description' => 'Ids of files the user already owns to copy into the run.',
                    ],
                    'timeout_sec' => [
                        'type' => 'integer',
                        'minimum' => self::MIN_TIMEOUT_SEC,
                        'maximum' => self::MAX_TIMEOUT_SEC,
                        'description' => 'Seconds the run may take (1–300).',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{text: string, isError: bool}
     */
    public function execute(
        array $input,
        User $user,
        string $invokedVia,
        ?RuntimeProfile $assistant = null,
        ?int $messageId = null,
        ?int $promptId = null,
    ): array {
        return $this->invoker->invoke($user, $input, $invokedVia, $assistant, $messageId, $promptId);
    }
}
