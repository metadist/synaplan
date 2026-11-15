<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Service\Document\Model\WordBlock;
use App\Service\Document\Model\WordModel;
use App\Service\Document\Persist\DocumentTextProjector;
use App\Service\Document\Tool\DocumentSession;

/**
 * Puts a markdown document that was already in the request into a new Word
 * file when the tool loop only kept the title.
 *
 * Document tools insert one block per call. A model that stops after the
 * heading still counts as a successful edit, and the file is saved from that
 * heading alone. The caller passes the plan itself — not the assembled chat
 * turn — so attachment text and generation rules are not copied into the
 * file and cannot make a finished document look incomplete. An edit of an
 * existing file is left alone, and so is a request to shorten the text.
 */
final class ProvidedWordBody
{
    /** Below this, the request is a title or a sentence, not a document body. */
    private const MIN_BODY_CHARS = 180;

    /**
     * The tool result has to cover at least this share of the provided text.
     * A heading-only file is a few dozen characters against a few thousand.
     */
    private const MIN_COVERAGE = 0.5;

    private function __construct()
    {
    }

    public static function restore(DocumentSession $session, string $documentBody): bool
    {
        if (null !== $session->fileId || null === $session->word()) {
            return false;
        }

        $markdown = self::markdownFromBody($documentBody);
        if (null === $markdown) {
            return false;
        }

        $parsed = self::parse($markdown);
        if (!self::shouldReplace($session->word(), $parsed)) {
            return false;
        }

        $session->model = $parsed;

        return true;
    }

    private static function markdownFromBody(string $text): ?string
    {
        $text = self::unwrapChannelEnvelope(self::stripTurnScaffolding($text));
        if (1 !== preg_match('/^#{1,6}[ \t]+\S/m', $text, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $offset = $match[0][1];
        $preamble = substr($text, 0, $offset);
        $markdown = trim(substr($text, $offset));
        if ('' === $markdown || self::asksToShorten($preamble) || self::trailingInstructionAsksToShorten($markdown)) {
            return null;
        }

        return $markdown;
    }

    /**
     * Format rules, assistant instructions and the assembler's attachment
     * block are appended after the plan. They are not document content.
     */
    private static function stripTurnScaffolding(string $text): string
    {
        $previous = null;
        while ($previous !== $text) {
            $previous = $text;
            $text = preg_replace(
                '/\n+The file MUST be a \.[a-z0-9]+ file \(the user explicitly asked for this format\)\.\s*$/i',
                '',
                $text,
            ) ?? $text;
            $text = preg_replace(
                '/\n+Follow these assistant instructions for the file content\. Still answer with the file envelope only\.\n.*\z/s',
                '',
                $text,
            ) ?? $text;
            $text = preg_replace('/\n+---\s*\n+User provided\b.*\z/s', '', $text) ?? $text;
        }

        return $text;
    }

    private static function asksToShorten(string $text): bool
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (self::isShortenCommand($line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A command is its own line. A sentence that merely mentions an abstract
     * or a summary is the document, not a request to shorten it.
     */
    private static function isShortenCommand(string $line): bool
    {
        $line = trim($line);
        if ('' === $line) {
            return false;
        }
        if (1 === preg_match('/^(?:please\s+|bitte\s+)?(?:summarize|summarise|shorten|condense|kürze|kuerze|zusammenfassen)\b/iu', $line)) {
            return true;
        }

        return 1 === preg_match('/^(?:please\s+|bitte\s+)?fasse?\b.{0,160}\bzusammen\b/iu', $line);
    }

    private static function trailingInstructionAsksToShorten(string $markdown): bool
    {
        return self::isShortenCommand(self::lastNonEmptyLine($markdown));
    }

    private static function lastNonEmptyLine(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        for ($i = count($lines) - 1; $i >= 0; --$i) {
            if ('' !== trim($lines[$i])) {
                return trim($lines[$i]);
            }
        }

        return '';
    }

    private static function shouldReplace(WordModel $current, WordModel $parsed): bool
    {
        $projector = new DocumentTextProjector();
        $need = self::contentChars($projector->project($parsed));
        if ($need < self::MIN_BODY_CHARS) {
            return false;
        }

        $have = self::contentChars($projector->project($current));

        return $have < (int) ($need * self::MIN_COVERAGE);
    }

    private static function contentChars(string $text): int
    {
        $collapsed = preg_replace('/\s+/u', '', $text) ?? $text;

        return mb_strlen($collapsed);
    }

    /**
     * Buffered channels wrap the turn as {"BTEXT":"…"}. The markdown lives in
     * that field, not at the start of the JSON.
     */
    private static function unwrapChannelEnvelope(string $text): string
    {
        $trimmed = trim($text);
        if (!str_starts_with($trimmed, '{')) {
            return $text;
        }

        try {
            $data = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $text;
        }

        if (!is_array($data) || !is_string($data['BTEXT'] ?? null) || '' === trim($data['BTEXT'])) {
            return $text;
        }

        return $data['BTEXT'];
    }

    private static function parse(string $markdown): WordModel
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $blocks = [];
        $paragraph = [];
        $count = count($lines);

        $flush = static function () use (&$paragraph, &$blocks): void {
            $text = trim(implode(' ', $paragraph));
            $paragraph = [];
            if ('' === $text) {
                return;
            }
            $blocks[] = self::block(WordBlock::TYPE_PARAGRAPH, ['text' => self::stripInline($text)]);
        };

        for ($i = 0; $i < $count; ++$i) {
            $line = $lines[$i];
            $trimmed = trim($line);
            if ('' === $trimmed || '---' === $trimmed || '{{TOC}}' === $trimmed) {
                $flush();
                if ('{{TOC}}' === $trimmed) {
                    $blocks[] = self::block(WordBlock::TYPE_TOC);
                }
                continue;
            }

            if (1 === preg_match('/^(#{1,6})[ \t]+(.+)$/', $trimmed, $heading)) {
                $flush();
                $blocks[] = self::block(WordBlock::TYPE_HEADING, [
                    'text' => self::stripInline($heading[2]),
                    'level' => strlen($heading[1]),
                ]);
                continue;
            }

            if (self::startsTable($lines, $i)) {
                $flush();
                $rows = [];
                $rows[] = self::tableCells($lines[$i]);
                $i += 2;
                while ($i < $count && self::isTableRow($lines[$i])) {
                    $rows[] = self::tableCells($lines[$i]);
                    ++$i;
                }
                --$i;
                $blocks[] = self::block(WordBlock::TYPE_TABLE, ['rows' => $rows]);
                continue;
            }

            if (1 === preg_match('/^([-*+]|\d+\.)[ \t]+(.+)$/', $trimmed, $item)) {
                $flush();
                $blocks[] = self::block(WordBlock::TYPE_PARAGRAPH, [
                    'text' => '• '.self::stripInline($item[2]),
                ]);
                continue;
            }

            $paragraph[] = $trimmed;
        }
        $flush();

        return new WordModel($blocks);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function block(string $type, array $payload = []): WordBlock
    {
        return new WordBlock(uniqid('blk_', true), $type, $payload);
    }

    /**
     * @param list<string> $lines
     */
    private static function startsTable(array $lines, int $index): bool
    {
        return self::isTableRow($lines[$index])
            && isset($lines[$index + 1])
            && 1 === preg_match('/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?$/', trim($lines[$index + 1]));
    }

    private static function isTableRow(string $line): bool
    {
        $trimmed = trim($line);

        return str_contains($trimmed, '|') && !str_starts_with($trimmed, '|-');
    }

    /**
     * @return list<string>
     */
    private static function tableCells(string $line): array
    {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '|')) {
            $trimmed = substr($trimmed, 1);
        }
        if (str_ends_with($trimmed, '|')) {
            $trimmed = substr($trimmed, 0, -1);
        }

        return array_map(
            static fn (string $cell): string => self::stripInline(trim($cell)),
            explode('|', $trimmed),
        );
    }

    private static function stripInline(string $text): string
    {
        $links = [];
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]*)\)/',
            static function (array $match) use (&$links): string {
                $label = self::stripEmphasis(trim($match[1]));
                $url = trim($match[2]);
                $token = "\x00".count($links)."\x00";
                $links[$token] = '' === $url || $label === $url ? $label : $label.' ('.$url.')';

                return $token;
            },
            $text,
        ) ?? $text;

        return trim(strtr(self::stripEmphasis($text), $links));
    }

    private static function stripEmphasis(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/', '$1', $text) ?? $text;

        return str_replace('`', '', $text);
    }
}
