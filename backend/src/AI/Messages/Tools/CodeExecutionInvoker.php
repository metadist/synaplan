<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\Entity\User;
use App\Service\Multitask\Execution\Runner\CodeRunRunner;
use App\Service\Runtime\RuntimeProfile;
use App\Service\Tool\Policy\PolicyContext;

/**
 * Thin adapter so both gateway loops call {@see CodeRunRunner} and never
 * talk to the sidecar themselves.
 */
final readonly class CodeExecutionInvoker
{
    public function __construct(
        private CodeRunRunner $runner,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{text: string, isError: bool}
     */
    public function invoke(
        User $user,
        array $input,
        string $invokedVia,
        ?RuntimeProfile $assistant = null,
        ?int $messageId = null,
        ?int $promptId = null,
    ): array {
        $parsed = $this->parseInput($input);
        if (null !== $parsed['error']) {
            return ['text' => $parsed['error'], 'isError' => true];
        }

        $result = $this->runner->executeDirect(
            $user,
            $parsed['language'],
            $parsed['code'],
            $parsed['input_file_ids'],
            $parsed['timeout_sec'],
            $invokedVia,
            $assistant,
            null,
            $promptId,
            null,
            null,
            PolicyContext::Interactive,
            false,
            false,
            $messageId,
        );

        return [
            'text' => json_encode($this->payload($result), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            'isError' => !\in_array($result['outcome'], ['ok', 'waiting_approval'], true),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{language: string, code: string, input_file_ids: list<int>, timeout_sec: ?int, error: ?string}
     */
    private function parseInput(array $input): array
    {
        $language = is_string($input['language'] ?? null) ? strtolower(trim($input['language'])) : '';
        if (!\in_array($language, ['python', 'node'], true)) {
            return $this->invalid('code_execution requires `language` to be python or node.');
        }

        $code = is_string($input['code'] ?? null) ? $input['code'] : '';
        if ('' === trim($code)) {
            return $this->invalid('code_execution requires a non-empty `code` script.');
        }
        if (mb_strlen($code) > CodeExecutionTool::MAX_CODE_CHARS) {
            return $this->invalid(sprintf(
                'code_execution `code` exceeds the %d character maximum.',
                CodeExecutionTool::MAX_CODE_CHARS,
            ));
        }

        $ids = [];
        $rawIds = $input['input_file_ids'] ?? [];
        if (!is_array($rawIds)) {
            return $this->invalid('code_execution `input_file_ids` must be an array of integers.');
        }
        if (\count($rawIds) > CodeExecutionTool::MAX_INPUT_FILES) {
            return $this->invalid(sprintf(
                'code_execution accepts at most %d input files.',
                CodeExecutionTool::MAX_INPUT_FILES,
            ));
        }
        foreach ($rawIds as $id) {
            if (!is_numeric($id)) {
                return $this->invalid('code_execution `input_file_ids` must be integers.');
            }
            $ids[] = (int) $id;
        }

        $timeout = $input['timeout_sec'] ?? null;
        $timeoutSec = null;
        if (null !== $timeout && '' !== $timeout) {
            if (!is_numeric($timeout)) {
                return $this->invalid('code_execution `timeout_sec` must be an integer.');
            }
            $timeoutSec = max(
                CodeExecutionTool::MIN_TIMEOUT_SEC,
                min(CodeExecutionTool::MAX_TIMEOUT_SEC, (int) $timeout),
            );
        }

        return [
            'language' => $language,
            'code' => $code,
            'input_file_ids' => $ids,
            'timeout_sec' => $timeoutSec,
            'error' => null,
        ];
    }

    /**
     * @return array{language: string, code: string, input_file_ids: list<int>, timeout_sec: ?int, error: string}
     */
    private function invalid(string $error): array
    {
        return [
            'language' => '',
            'code' => '',
            'input_file_ids' => [],
            'timeout_sec' => null,
            'error' => $error,
        ];
    }

    /**
     * @param array{
     *     outcome: string,
     *     status: string,
     *     exit_code: ?int,
     *     stdout: string,
     *     stderr: string,
     *     artefacts: list<array{file_id: int, name: string, mime: string, size: int}>,
     *     error: ?string,
     *     approval_id: ?int,
     *     compute_run_id: ?string
     * } $result
     *
     * @return array<string, mixed>
     */
    private function payload(array $result): array
    {
        if ('waiting_approval' === $result['outcome']) {
            return [
                'status' => 'waiting_approval',
                'exit_code' => null,
                'stdout' => '',
                'stderr' => '',
                'artefacts' => [],
                'approval_id' => $result['approval_id'],
            ];
        }

        return [
            'status' => $result['status'],
            'exit_code' => $result['exit_code'],
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
            'artefacts' => $result['artefacts'],
            'error' => $result['error'],
        ];
    }
}
