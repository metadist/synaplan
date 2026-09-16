<?php

declare(strict_types=1);

namespace App\Service\Context;

/**
 * Compact description of an attachment for the ROUTING models (sorter,
 * planner). Those models only decide *what to do* with the file; they never
 * need its full text, and a 250 kB spreadsheet as JSON blows their window and
 * turns every classification into the fallback path.
 *
 * The digest tells them: what kind of content it is, how big, how it is
 * structured (sections / sheets, column names), and shows the beginning and
 * end so the intent ("analyse this", "translate this") can still be read.
 *
 * Pure function of the text — no I/O, deterministic, cheap.
 */
final class AttachmentDigest
{
    private const HEADING_LIMIT = 12;
    private const HEAD_SHARE = 0.65;

    public function __construct(
        private readonly TokenEstimator $tokenEstimator,
        private readonly ?ContextFittingConfig $config = null,
    ) {
    }

    /**
     * Routing view of an attachment text using the operator thresholds:
     * verbatim up to ROUTING_FULL_TEXT_MAX_CHARS, digest above.
     */
    public function forRoutingWithConfig(string $text, ?int $userId, ?string $fileType = null): string
    {
        $passThrough = $this->config?->routingFullTextMaxChars($userId) ?? ContextFittingConfig::DEFAULT_ROUTING_FULL_TEXT_MAX_CHARS;
        $digestChars = $this->config?->routingDigestChars($userId) ?? ContextFittingConfig::DEFAULT_ROUTING_DIGEST_CHARS;

        return $this->forRouting($text, $digestChars, $fileType, $passThrough);
    }

    /**
     * Returns `$text` unchanged when it is at most `$passThroughChars` long
     * (default: `$maxChars`); otherwise a digest of roughly `$maxChars`
     * characters. The pass-through threshold keeps routing behaviour identical
     * for ordinary attachments — only genuinely large files are digested.
     */
    public function forRouting(string $text, int $maxChars, ?string $fileType = null, ?int $passThroughChars = null): string
    {
        $text = trim($text);
        $length = mb_strlen($text);
        if ($length <= max($maxChars, $passThroughChars ?? $maxChars)) {
            return $text;
        }

        $header = sprintf(
            '[Attachment digest for routing — %s, %s characters (~%s tokens). Only structure and excerpts are shown here; the answering step receives the content fitted to its model window.]',
            $this->describeKind($text, $fileType),
            number_format($length),
            number_format($this->tokenEstimator->estimate($text)),
        );

        $structure = $this->describeStructure($text);

        $fixed = mb_strlen($header) + mb_strlen($structure) + 40;
        $excerptBudget = max(200, $maxChars - $fixed);
        $headChars = (int) floor($excerptBudget * self::HEAD_SHARE);
        $tailChars = $excerptBudget - $headChars;

        $head = $this->cutAtLine(mb_substr($text, 0, $headChars), true);
        $tail = $this->cutAtLine(mb_substr($text, $length - $tailChars), false);

        $parts = [$header];
        if ('' !== $structure) {
            $parts[] = $structure;
        }
        $parts[] = "--- BEGINNING ---\n".$head;
        $parts[] = "--- END ---\n".$tail;

        return implode("\n\n", $parts);
    }

    private function describeKind(string $text, ?string $fileType): string
    {
        $type = strtolower(trim((string) $fileType));
        $label = match ($type) {
            'xlsx', 'xls', 'xlsm', 'csv', 'ods' => 'spreadsheet',
            'docx', 'doc', 'odt', 'rtf' => 'text document',
            'pptx', 'ppt', 'odp' => 'presentation',
            'pdf' => 'PDF document',
            'md', 'txt' => 'plain text',
            'json', 'xml', 'yaml', 'yml' => 'structured data',
            '' => '',
            default => $type.' file',
        };

        if ('' === $label) {
            $label = 1 === preg_match('/^\|.*\|\s*$/m', $text) ? 'tabular content' : 'text content';
        }

        return $label;
    }

    private function describeStructure(string $text): string
    {
        $lines = [];

        if (preg_match_all('/^## (.+)$/mu', $text, $m) > 0) {
            $headings = array_slice($m[1], 0, self::HEADING_LIMIT);
            $more = count($m[1]) - count($headings);
            $lines[] = 'Sections: '.implode(' | ', array_map(static fn (string $h): string => trim($h), $headings))
                .($more > 0 ? sprintf(' (+%d more)', $more) : '');
        }

        if (1 === preg_match('/^(\|[^\n]*\|)\n\|[\s:|-]+\|$/mu', $text, $t)) {
            $columns = array_values(array_filter(array_map('trim', explode('|', trim($t[1], '| '))), static fn (string $c): bool => '' !== $c));
            if ([] !== $columns) {
                $lines[] = 'First table columns: '.implode(', ', array_slice($columns, 0, 25))
                    .(count($columns) > 25 ? sprintf(' (+%d more)', count($columns) - 25) : '');
            }
            $rows = preg_match_all('/^\|[^\n]*\|$/mu', $text);
            if (false !== $rows && $rows > 2) {
                $lines[] = sprintf('Table rows in extract: about %s', number_format($rows - 2));
            }
        }

        return implode("\n", $lines);
    }

    private function cutAtLine(string $excerpt, bool $keepStart): string
    {
        if ($keepStart) {
            $pos = mb_strrpos($excerpt, "\n");
            if (false !== $pos && $pos > mb_strlen($excerpt) * 0.6) {
                $excerpt = mb_substr($excerpt, 0, $pos);
            }

            return rtrim($excerpt);
        }

        $pos = mb_strpos($excerpt, "\n");
        if (false !== $pos && $pos < mb_strlen($excerpt) * 0.4) {
            $excerpt = mb_substr($excerpt, $pos + 1);
        }

        return ltrim($excerpt);
    }
}
