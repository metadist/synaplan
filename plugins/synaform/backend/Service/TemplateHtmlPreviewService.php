<?php

declare(strict_types=1);

namespace Plugin\Synaform\Service;

use Psr\Log\LoggerInterface;

/**
 * Converts a DOCX target template into an HTML "skeleton" used for the live
 * preview panel. The conversion is intentionally lightweight: we only care
 * about the layout primitives our profile templates use (paragraphs, runs with
 * bold/italic/underline, headings, basic lists, tables, and of course our own
 * {{placeholder}} tokens).
 *
 * The emitted HTML is an island:
 *   - `<div class="tx-preview">…</div>` at the outermost level
 *   - `<span class="tx-ph" data-tx-key="KEY">{{KEY}}</span>` around every
 *     {{placeholder}} so the frontend can swap them by data-tx-key
 *   - repeating row placeholders (`{{group.col.N}}`) tag their `<tr>` with
 *     `data-tx-row-template="group"` and the cells carry
 *     `data-tx-key="group.col"` (we strip the `.N` at skeleton time because
 *     the live preview expands the row via JS on render)
 *   - a minimal `<style>` block is injected by the caller, not here — we stay
 *     pure data so the cache is reusable.
 *
 * Intentionally unsupported (these degrade to "missing" in the preview; they
 * stay correct in the true-preview PDF path because that uses the original
 * .docx):
 *   - inline images, shapes, drawings
 *   - headers / footers
 *   - complex fields ({FIELD …}), TOC, cross-refs
 *   - numbering with multi-level style overrides
 *
 * The output is deterministic given the input .docx, so the cache key in
 * plugin_data is just the template id plus a schema version.
 */
final class TemplateHtmlPreviewService
{
    /** Bump when emitted HTML structure changes — invalidates cached skeletons. */
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Build an HTML skeleton from the given .docx file.
     *
     * @return array{
     *     schema_version: int,
     *     html: string,
     *     placeholders: list<string>,
     *     row_groups: list<string>,
     *     generated_at: string,
     * }
     */
    public function build(string $docxPath): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($docxPath)) {
            $this->logger->warning('TemplateHtmlPreviewService: cannot open', ['path' => $docxPath]);

            return $this->emptyResult();
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (false === $xml) {
            return $this->emptyResult();
        }

        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        // Use libxml's error queue instead of `@` so genuine XML parse
        // problems (malformed DOCX, truncated upload, etc.) make it into
        // the application log instead of being silently dropped.
        $previousInternalErrors = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousInternalErrors);
        if (!$loaded) {
            $messages = array_map(static fn (\LibXMLError $e): string => trim($e->message), $errors);
            $this->logger->warning('TemplateHtmlPreviewService: invalid document.xml', [
                'errors' => array_slice($messages, 0, 3),
            ]);

            return $this->emptyResult();
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $body = $xpath->query('//w:body')->item(0);
        if (!$body instanceof \DOMElement) {
            return $this->emptyResult();
        }

        $state = ['placeholders' => [], 'row_groups' => []];
        $html = $this->renderBlock($body, $xpath, $state);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'html' => '<div class="tx-preview">'.$html.'</div>',
            'placeholders' => array_values(array_unique($state['placeholders'])),
            'row_groups' => array_values(array_unique($state['row_groups'])),
            'generated_at' => date('c'),
        ];
    }

    private function emptyResult(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'html' => '<div class="tx-preview"><p><em>Preview unavailable.</em></p></div>',
            'placeholders' => [],
            'row_groups' => [],
            'generated_at' => date('c'),
        ];
    }

    /**
     * Render direct children of $container (body or table cell): paragraphs and
     * nested tables. Inherits the caller's state for placeholder tracking.
     */
    private function renderBlock(\DOMElement $container, \DOMXPath $xpath, array &$state): string
    {
        $out = '';
        foreach ($container->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $local = $node->localName;
            if ('p' === $local) {
                $out .= $this->renderParagraph($node, $xpath, $state);
            } elseif ('tbl' === $local) {
                $out .= $this->renderTable($node, $xpath, $state);
            } elseif ('sectPr' === $local) {
                continue;
            }
        }

        return $out;
    }

    private function renderParagraph(\DOMElement $p, \DOMXPath $xpath, array &$state): string
    {
        $pStyle = $this->paragraphStyle($p, $xpath);
        $runsHtml = $this->renderRuns($p, $xpath, $state);

        if ('' === trim(strip_tags($runsHtml))) {
            // Empty paragraph — still render an empty <p> so vertical spacing is kept.
            return '<p class="tx-empty">&nbsp;</p>';
        }

        $tag = $pStyle['tag'];
        $classes = $pStyle['classes'];
        $style = $pStyle['inline_style'];

        $attr = '';
        if ('' !== $classes) {
            $attr .= ' class="'.htmlspecialchars($classes, ENT_QUOTES).'"';
        }
        if ('' !== $style) {
            $attr .= ' style="'.htmlspecialchars($style, ENT_QUOTES).'"';
        }

        return '<'.$tag.$attr.'>'.$runsHtml.'</'.$tag.'>';
    }

    /**
     * Decide HTML tag + classes based on the paragraph's <w:pPr>/<w:pStyle>
     * and a few common style names.
     *
     * @return array{tag: string, classes: string, inline_style: string}
     */
    private function paragraphStyle(\DOMElement $p, \DOMXPath $xpath): array
    {
        $styleNode = $xpath->query('./w:pPr/w:pStyle/@w:val', $p)->item(0);
        $styleId = $styleNode?->nodeValue ?? '';

        // Heading1..Heading6 become h1..h6
        if (preg_match('/^Heading(\d)$/i', $styleId, $m)) {
            $level = min(6, max(1, (int) $m[1]));

            return ['tag' => 'h'.$level, 'classes' => 'tx-h'.$level, 'inline_style' => ''];
        }

        $classes = 'tx-p';
        if ('' !== $styleId) {
            $safeId = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $styleId);
            $classes .= ' tx-style-'.strtolower((string) $safeId);
        }

        // Bulleted list detection via <w:numPr> presence.
        $isList = $xpath->query('./w:pPr/w:numPr', $p)->length > 0;
        if ($isList) {
            $classes .= ' tx-li';
        }

        // Alignment
        $jc = $xpath->query('./w:pPr/w:jc/@w:val', $p)->item(0)?->nodeValue ?? '';
        $style = '';
        if ('center' === $jc || 'right' === $jc || 'both' === $jc || 'justify' === $jc) {
            $style = 'text-align:'.('both' === $jc ? 'justify' : $jc).';';
        }

        return ['tag' => 'p', 'classes' => $classes, 'inline_style' => $style];
    }

    /**
     * Flatten all <w:r> runs inside the paragraph, handle placeholder tokenisation
     * across run boundaries, and emit span-wrapped HTML.
     *
     * Walks the paragraph's children in document order so that runs nested
     * inside containers like `<w:hyperlink>`, `<w:smartTag>`, `<w:fldSimple>`,
     * `<w:sdt>` etc. are still visible to the preview. Without this,
     * hyperlink anchor text and other inline-wrapped content silently
     * disappears from the rendered preview.
     */
    private function renderRuns(\DOMElement $p, \DOMXPath $xpath, array &$state): string
    {
        $runs = [];
        $this->collectRuns($p, $xpath, $runs);

        if (empty($runs)) {
            return '';
        }

        // 2. Coalesce consecutive runs with identical style and scan for placeholders.
        //    Placeholders can span multiple runs; we keep the FIRST run's style for
        //    the whole placeholder span (accurate in practice — placeholders rarely
        //    straddle formatting boundaries).
        $flat = '';
        $runMap = []; // per flat-char, the index of the contributing run
        foreach ($runs as $idx => $r) {
            $textLen = strlen($r['text']);
            for ($i = 0; $i < $textLen; ++$i) {
                $runMap[] = $idx;
            }
            $flat .= $r['text'];
        }

        return $this->flattenToHtml($flat, $runMap, $runs, $state);
    }

    /**
     * Recursively walk paragraph children in document order, pushing every
     * `<w:r>` into $runs and recursing into inline containers
     * (`<w:hyperlink>`, `<w:smartTag>`, `<w:fldSimple>`, `<w:sdt>`,
     * `<w:sdtContent>`) so their wrapped runs are still picked up.
     * Skips known non-run inline structures (proofErr, bookmarkStart, …).
     *
     * Also handles paragraph-level `<w:br>` and `<w:tab>` siblings (PhpWord
     * sometimes emits these outside a run): they get appended to the
     * previous run's text so the line break / tab still lands in the
     * correct position in the flat text stream.
     *
     * @param array<int, array{text: string, style: array<string, mixed>}> $runs (out)
     */
    private function collectRuns(\DOMElement $node, \DOMXPath $xpath, array &$runs): void
    {
        static $recurseInto = ['hyperlink', 'smartTag', 'fldSimple', 'sdt', 'sdtContent', 'customXml'];

        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $local = $child->localName;
            if ('r' === $local) {
                $runs[] = [
                    'text' => $this->runText($child),
                    'style' => $this->runStyle($child, $xpath),
                ];
            } elseif (in_array($local, $recurseInto, true)) {
                $this->collectRuns($child, $xpath, $runs);
            } elseif ('br' === $local || 'tab' === $local) {
                $whitespace = 'br' === $local ? "\n" : "\t";
                if ([] === $runs) {
                    $runs[] = ['text' => $whitespace, 'style' => []];
                } else {
                    $runs[count($runs) - 1]['text'] .= $whitespace;
                }
            }
        }
    }

    /**
     * Build a run's visible text by walking its children in document
     * order so that `<w:t>`, `<w:br>`, and `<w:tab>` end up at their real
     * positions instead of all line-breaks being moved to the run's tail.
     */
    private function runText(\DOMElement $run): string
    {
        $text = '';
        foreach ($run->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            switch ($child->localName) {
                case 't':
                    $text .= $child->nodeValue ?? '';
                    break;
                case 'br':
                    $text .= "\n";
                    break;
                case 'tab':
                    $text .= "\t";
                    break;
                    // Other run children (rPr, drawing, fldChar, instrText, …)
                    // do not contribute visible characters to the live preview.
            }
        }

        return $text;
    }

    /**
     * Emit HTML for a paragraph's flat text, inserting <span class="tx-ph"> for
     * every `{{placeholder}}` and preserving per-character styling.
     */
    private function flattenToHtml(string $flat, array $runMap, array $runs, array &$state): string
    {
        if ('' === $flat) {
            return '';
        }

        $segments = [];
        $offset = 0;
        if (preg_match_all('/\{\{([^{}]+)\}\}/', $flat, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => [$token, $pos]) {
                if ($pos > $offset) {
                    $segments[] = ['type' => 'text', 'start' => $offset, 'end' => $pos];
                }
                $rawKey = trim($matches[1][$i][0]);
                $segments[] = [
                    'type' => 'ph',
                    'start' => $pos,
                    'end' => $pos + strlen($token),
                    'key' => $rawKey,
                ];
                $offset = $pos + strlen($token);
            }
        }
        if ($offset < strlen($flat)) {
            $segments[] = ['type' => 'text', 'start' => $offset, 'end' => strlen($flat)];
        }

        $out = '';
        foreach ($segments as $seg) {
            if ('text' === $seg['type']) {
                $out .= $this->renderTextSlice($flat, $runMap, $runs, $seg['start'], $seg['end']);
            } else {
                $state['placeholders'][] = $seg['key'];
                $normalized = $this->normalizePlaceholderKey($seg['key'], $state);
                $out .= $this->renderPlaceholderSpan(
                    $seg['key'],
                    $normalized,
                    $this->styleAtOffset($runMap, $runs, $seg['start']),
                );
            }
        }

        return $out;
    }

    /**
     * Normalize the raw placeholder key into (display key, row group meta).
     * `stations.time.N` → ['stations.time', 'stations'] (the .N is expanded by JS).
     *
     * @return array{key: string, row_group: ?string}
     */
    private function normalizePlaceholderKey(string $rawKey, array &$state): array
    {
        // Row repeaters: `group.col.N`
        if (preg_match('/^([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)\.N$/', $rawKey, $m)) {
            $state['row_groups'][] = $m[1];

            return ['key' => $m[1].'.'.$m[2], 'row_group' => $m[1]];
        }

        return ['key' => $rawKey, 'row_group' => null];
    }

    private function renderPlaceholderSpan(string $rawKey, array $normalized, array $style): string
    {
        $styleAttr = $this->styleToCss($style);
        $attrs = ' class="tx-ph" data-tx-key="'.htmlspecialchars($normalized['key'], ENT_QUOTES).'"';
        $attrs .= ' data-tx-raw="'.htmlspecialchars($rawKey, ENT_QUOTES).'"';
        if (null !== $normalized['row_group']) {
            $attrs .= ' data-tx-row-group="'.htmlspecialchars($normalized['row_group'], ENT_QUOTES).'"';
        }
        if ('' !== $styleAttr) {
            $attrs .= ' style="'.htmlspecialchars($styleAttr, ENT_QUOTES).'"';
        }

        return '<span'.$attrs.'>{{'.htmlspecialchars($rawKey, ENT_QUOTES).'}}</span>';
    }

    private function renderTextSlice(string $flat, array $runMap, array $runs, int $start, int $end): string
    {
        if ($start >= $end) {
            return '';
        }
        // Split slice by style transitions so bold/italic/etc. boundaries are preserved.
        $out = '';
        $segStart = $start;
        $currentStyle = $this->styleAtOffset($runMap, $runs, $start);
        for ($i = $start + 1; $i < $end; ++$i) {
            $nextStyle = $this->styleAtOffset($runMap, $runs, $i);
            if ($nextStyle !== $currentStyle) {
                $out .= $this->emitStyledText(substr($flat, $segStart, $i - $segStart), $currentStyle);
                $segStart = $i;
                $currentStyle = $nextStyle;
            }
        }
        $out .= $this->emitStyledText(substr($flat, $segStart, $end - $segStart), $currentStyle);

        return $out;
    }

    private function emitStyledText(string $text, array $style): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safe = str_replace("\n", '<br/>', $safe);
        $cls = [];
        if (!empty($style['bold'])) {
            $cls[] = 'tx-b';
        }
        if (!empty($style['italic'])) {
            $cls[] = 'tx-i';
        }
        if (!empty($style['underline'])) {
            $cls[] = 'tx-u';
        }
        if (empty($cls)) {
            return $safe;
        }

        return '<span class="'.implode(' ', $cls).'">'.$safe.'</span>';
    }

    /**
     * Look up the style that was active at character offset $i in the flat
     * paragraph text.
     */
    private function styleAtOffset(array $runMap, array $runs, int $i): array
    {
        if (!isset($runMap[$i])) {
            return [];
        }

        return $runs[$runMap[$i]]['style'] ?? [];
    }

    private function styleToCss(array $style): string
    {
        $parts = [];
        if (!empty($style['bold'])) {
            $parts[] = 'font-weight:bold';
        }
        if (!empty($style['italic'])) {
            $parts[] = 'font-style:italic';
        }
        if (!empty($style['underline'])) {
            $parts[] = 'text-decoration:underline';
        }

        return implode(';', $parts);
    }

    private function runStyle(\DOMElement $run, \DOMXPath $xpath): array
    {
        $style = [];
        if ($xpath->query('./w:rPr/w:b[not(@w:val="false") and not(@w:val="0")]', $run)->length > 0) {
            $style['bold'] = true;
        }
        if ($xpath->query('./w:rPr/w:i[not(@w:val="false") and not(@w:val="0")]', $run)->length > 0) {
            $style['italic'] = true;
        }
        if ($xpath->query('./w:rPr/w:u[@w:val and @w:val!="none"]', $run)->length > 0) {
            $style['underline'] = true;
        }

        return $style;
    }

    private function renderTable(\DOMElement $tbl, \DOMXPath $xpath, array &$state): string
    {
        $rows = [];
        foreach ($xpath->query('./w:tr', $tbl) as $tr) {
            if (!$tr instanceof \DOMElement) {
                continue;
            }
            $rows[] = $this->renderTableRow($tr, $xpath, $state);
        }
        if (empty($rows)) {
            return '';
        }

        return '<table class="tx-tbl"><tbody>'.implode('', $rows).'</tbody></table>';
    }

    private function renderTableRow(\DOMElement $tr, \DOMXPath $xpath, array &$state): string
    {
        $cellsHtml = [];
        $rowGroup = null;
        foreach ($xpath->query('./w:tc', $tr) as $tc) {
            if (!$tc instanceof \DOMElement) {
                continue;
            }
            $localState = ['placeholders' => [], 'row_groups' => []];
            $cellHtml = $this->renderBlock($tc, $xpath, $localState);

            // Propagate placeholders/row_groups to parent state
            $state['placeholders'] = array_merge($state['placeholders'], $localState['placeholders']);
            $state['row_groups'] = array_merge($state['row_groups'], $localState['row_groups']);

            if (null === $rowGroup && !empty($localState['row_groups'])) {
                $rowGroup = $localState['row_groups'][0];
            }
            $cellsHtml[] = '<td>'.$cellHtml.'</td>';
        }

        $attrs = '';
        if (null !== $rowGroup) {
            // This row contains `{{group.col.N}}` placeholders — the frontend
            // clones it per dataset row at render time.
            $attrs = ' data-tx-row-template="'.htmlspecialchars($rowGroup, ENT_QUOTES).'"';
        }

        return '<tr'.$attrs.'>'.implode('', $cellsHtml).'</tr>';
    }
}
