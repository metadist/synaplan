<?php

declare(strict_types=1);

namespace App\Service\File;

use Psr\Log\LoggerInterface;

/**
 * Plain text for RAG from an OpenCloud `.ocnote` file.
 *
 * The file is Tiptap JSON (`{"type":"doc",...}`), written by the text
 * editor's tiptap-json strategy. The tree can hold headings, paragraphs,
 * lists, task lists, quotes, code, tables, links and images. Images are
 * stored as data URLs; those bytes are not words and must not be indexed.
 */
final class OcnoteText
{
    private const MAX_NODES = 100000;

    private int $nodes = 0;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function extract(string $absolutePath, TextCleaner $cleaner, LoggerInterface $logger): array
    {
        $meta = [
            'mime' => 'application/json',
            'ext' => 'ocnote',
            'file' => basename($absolutePath),
        ];
        $text = self::fromFile($absolutePath);
        if (null === $text) {
            $logger->info('OcnoteText: file is not a Tiptap document', $meta);

            return ['', ['strategy' => 'ocnote_invalid'] + $meta];
        }

        $text = $cleaner->clean($text);
        $logger->info('OcnoteText: extracted OpenCloud note', [
            'strategy' => 'ocnote',
            'bytes' => strlen($text),
        ] + $meta);

        return [$text, ['strategy' => 'ocnote'] + $meta];
    }

    public static function fromFile(string $absolutePath): ?string
    {
        if (!is_file($absolutePath)) {
            return null;
        }

        $raw = file_get_contents($absolutePath);
        if (!is_string($raw)) {
            return null;
        }

        return self::fromJson($raw);
    }

    public static function fromJson(string $json): ?string
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || 'doc' !== ($decoded['type'] ?? null)) {
            return null;
        }

        return (new self())->render($decoded);
    }

    /**
     * @param array<mixed> $node
     */
    private function render(array $node): string
    {
        if ($this->nodes >= self::MAX_NODES) {
            return '';
        }
        ++$this->nodes;

        $type = (string) ($node['type'] ?? '');

        return match ($type) {
            'doc', 'blockquote' => $this->blocks($type, $node),
            'paragraph' => $this->plain($node),
            'heading' => $this->heading($node),
            'bulletList' => $this->listBlock($node, 'bullet'),
            'orderedList' => $this->listBlock($node, 'ordered'),
            'taskList' => $this->listBlock($node, 'task'),
            'codeBlock' => $this->codeBlock($node),
            'horizontalRule' => '---',
            'hardBreak' => "\n",
            'image' => $this->image($node),
            'table' => $this->table($node),
            'text' => (string) ($node['text'] ?? '').$this->linkSuffix($node),
            default => $this->fallback($node),
        };
    }

    /**
     * @param array<mixed> $node
     */
    private function blocks(string $type, array $node): string
    {
        $body = $this->join($node['content'] ?? null, "\n\n");
        if ('blockquote' !== $type || '' === $body) {
            return $body;
        }

        $lines = explode("\n", $body);

        return implode("\n", array_map(static fn (string $line): string => '> '.$line, $lines));
    }

    /**
     * @param array<mixed> $node
     */
    private function heading(array $node): string
    {
        $level = (int) ($this->attrs($node)['level'] ?? 1);
        $level = max(1, min(6, $level));
        $text = trim($this->plain($node));

        return str_repeat('#', $level).' '.$text;
    }

    /**
     * @param array<mixed> $node
     */
    private function listBlock(array $node, string $kind): string
    {
        $start = (int) ($this->attrs($node)['start'] ?? 1);
        if ($start < 1) {
            $start = 1;
        }

        $lines = [];
        $index = 0;
        foreach ($this->children($node) as $child) {
            $childType = (string) ($child['type'] ?? '');
            if (!in_array($childType, ['listItem', 'taskItem'], true)) {
                $rendered = $this->render($child);
                if ('' !== trim($rendered)) {
                    $lines[] = $rendered;
                }
                continue;
            }

            $marker = match ($kind) {
                'ordered' => ($start + $index).'. ',
                'task' => true === ($this->attrs($child)['checked'] ?? false) ? '[x] ' : '[ ] ',
                default => '- ',
            };
            ++$index;
            $lines[] = $this->prefixFirst($marker, $this->join($child['content'] ?? null, "\n"));
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<mixed> $node
     */
    private function codeBlock(array $node): string
    {
        $language = (string) ($this->attrs($node)['language'] ?? '');
        if (1 !== preg_match('/^[A-Za-z0-9_+-]{1,32}$/', $language)) {
            $language = '';
        }

        $code = $this->plain($node);
        if ('' === $language) {
            return $code;
        }

        return $language."\n".$code;
    }

    /**
     * @param array<mixed> $node
     */
    private function table(array $node): string
    {
        $rows = [];
        foreach ($this->children($node) as $row) {
            if ('tableRow' !== ($row['type'] ?? '')) {
                continue;
            }
            $cells = [];
            foreach ($this->children($row) as $cell) {
                $cellType = (string) ($cell['type'] ?? '');
                if (!in_array($cellType, ['tableCell', 'tableHeader'], true)) {
                    continue;
                }
                $text = $this->join($cell['content'] ?? null, ' ');
                $cells[] = trim((string) preg_replace('/\s+/u', ' ', $text));
            }
            if ([] !== $cells) {
                $rows[] = implode(' | ', $cells);
            }
        }

        return implode("\n", $rows);
    }

    /**
     * @param array<mixed> $node
     */
    private function image(array $node): string
    {
        $attrs = $this->attrs($node);
        $alt = trim((string) ($attrs['alt'] ?? ''));
        $title = trim((string) ($attrs['title'] ?? ''));
        $src = trim((string) ($attrs['src'] ?? ''));
        $label = '' !== $alt ? $alt : $title;

        $parts = [];
        if ('' !== $label) {
            $parts[] = $label;
        }
        if ($this->isPublicUrl($src)) {
            $parts[] = $src;
        }
        if ([] === $parts) {
            return '[image]';
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<mixed> $node
     */
    private function plain(array $node): string
    {
        $out = '';
        foreach ($this->children($node) as $child) {
            $type = (string) ($child['type'] ?? '');
            if ('text' === $type) {
                $out .= (string) ($child['text'] ?? '').$this->linkSuffix($child);
                continue;
            }
            if ('hardBreak' === $type) {
                $out .= "\n";
                continue;
            }
            $out .= $this->render($child);
        }

        return $out;
    }

    /**
     * @param array<mixed> $node
     */
    private function fallback(array $node): string
    {
        if (isset($node['text']) && is_string($node['text'])) {
            return $node['text'].$this->linkSuffix($node);
        }

        return $this->join($node['content'] ?? null, "\n\n");
    }

    /**
     * @param array<mixed> $node
     */
    private function linkSuffix(array $node): string
    {
        $marks = $node['marks'] ?? null;
        if (!is_array($marks)) {
            return '';
        }

        foreach ($marks as $mark) {
            if (!is_array($mark) || 'link' !== ($mark['type'] ?? '')) {
                continue;
            }
            $href = trim((string) ($this->attrs($mark)['href'] ?? ''));
            if (!$this->isSafeHref($href)) {
                continue;
            }
            $text = (string) ($node['text'] ?? '');
            if ($href === $text) {
                return '';
            }

            return ' ('.$href.')';
        }

        return '';
    }

    private function isSafeHref(string $href): bool
    {
        if ('' === $href || 1 === preg_match('/[\s\x00-\x1F\x7F]/', $href)) {
            return false;
        }
        if (str_starts_with($href, '#')) {
            return strlen($href) > 1;
        }
        if (1 === preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return 1 === preg_match('#^(https?://|mailto:)#i', $href);
        }

        return true;
    }

    private function isPublicUrl(string $src): bool
    {
        return 1 === preg_match('#^https?://#i', $src) && 1 !== preg_match('/[\s\x00-\x1F\x7F]/', $src);
    }

    private function prefixFirst(string $marker, string $body): string
    {
        if ('' === $body) {
            return rtrim($marker);
        }

        $lines = explode("\n", $body);
        $lines[0] = $marker.$lines[0];

        return implode("\n", $lines);
    }

    private function join(mixed $content, string $separator): string
    {
        if (!is_array($content)) {
            return '';
        }

        $blocks = [];
        foreach ($content as $child) {
            if (!is_array($child)) {
                continue;
            }
            $rendered = trim($this->render($child), "\n");
            if ('' === trim($rendered)) {
                continue;
            }
            $blocks[] = $rendered;
        }

        return implode($separator, $blocks);
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<array<mixed>>
     */
    private function children(array $node): array
    {
        $content = $node['content'] ?? null;
        if (!is_array($content)) {
            return [];
        }

        $children = [];
        foreach ($content as $child) {
            if (is_array($child)) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private function attrs(array $node): array
    {
        $attrs = $node['attrs'] ?? null;

        return is_array($attrs) ? $attrs : [];
    }
}
