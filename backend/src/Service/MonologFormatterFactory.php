<?php

namespace App\Service;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;

class MonologFormatterFactory
{
    public function __construct(
        private string $logFormat,
    ) {
    }

    public function createFormatter(): FormatterInterface
    {
        return match ($this->logFormat) {
            'line' => (function() {
                $formatter = new LineFormatter(
                    format: "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                    dateFormat: 'Y-m-d H:i:s',
                    allowInlineLineBreaks: true,
                    ignoreEmptyContextAndExtra: false
                );
                $formatter->allowInlineLineBreaks(true);
                $formatter->includeStacktraces(true);
                return $formatter;
            })(),
            default => new JsonFormatter(
                batchMode: JsonFormatter::BATCH_MODE_NEWLINES,
                appendNewline: true,
                ignoreEmptyContextAndExtra: false,
                includeStacktraces: false
            ),
        };
    }
}
