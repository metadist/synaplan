<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use App\Service\Document\DocumentKind;
use App\Service\Document\Tool\DocumentSession;
use App\Service\Document\Tool\DocumentToolInterface;
use App\Service\Document\Tool\DocumentToolResult;

/**
 * Document tool whose result is longer than the loop's clamp, so the snapshot
 * records the ellipsis the loop adds and not the whole payload.
 */
final class LongEchoDocumentTool implements DocumentToolInterface
{
    public const NAME = 'echo_long';
    public const PAYLOAD_CHARS = 13000;

    public function name(): string
    {
        return self::NAME;
    }

    public function declaration(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'Return a long note.',
                'parameters' => ['type' => 'object'],
            ],
        ];
    }

    public function appliesTo(): array
    {
        return [DocumentKind::XLSX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        return DocumentToolResult::ok(str_repeat('A', self::PAYLOAD_CHARS), 'processing.documentStepSetCells');
    }
}
