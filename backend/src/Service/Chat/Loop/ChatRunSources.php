<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\AI\Messages\Tools\WebFetchTool;
use App\AI\Messages\Tools\WebSearchTool;
use App\Service\Research\Run\Ledger\CanonicalUrl;

/**
 * The web sources a chat run read, and the answer's citations of them in
 * the form the research check reads.
 *
 * A source is a page this run's `web_search` listed or its `web_fetch`
 * read, with the text the model saw of it: the result's title and
 * snippets, or the page. A link in the answer to such a page becomes the
 * marker `[eN]` the check knows, after its text; a link to a page the run
 * did not read stays as it is and is not checked. The link's text is
 * fenced by {@see LABEL}: it names the source and is not a claim to check.
 */
final class ChatRunSources
{
    /** An invisible separator around a link's text, removed before anything is shown. */
    public const LABEL = "\u{2063}";

    /** A URL may hold balanced brackets, as in `wiki/Langenfeld_(Rheinland)`. */
    private const URL = 'https?:\/\/(?:[^\s()<>\[\]"]|\([^\s()<>]*\))+';
    private const LINK = '/\[([^\]\n]*)\]\(\s*<?('.self::URL.')>?(?:\s+"[^"\n]*")?\s*\)/u';
    private const AUTOLINK = '/<(https?:\/\/[^\s>]+)>/u';
    private const BARE = '/(?<![\w\/(\[<"=])'.self::URL.'/u';
    private const RESULT_START = '/\n(?=\[\d+\] )/';

    /**
     * @param list<array<string, mixed>> $messages the run's transcript
     *
     * @return array<string, string> canonical URL to the text the model saw of it
     */
    public static function read(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $content = $message['content'] ?? null;
            if ('tool' !== ($message['role'] ?? null) || !\is_string($content)) {
                continue;
            }
            $found = match ($message['name'] ?? null) {
                WebSearchTool::NAME => self::results($content),
                WebFetchTool::NAME => self::page($content),
                default => [],
            };
            foreach ($found as $url => $text) {
                $out[$url] = isset($out[$url]) ? $out[$url]."\n".$text : $text;
            }
        }

        return $out;
    }

    /**
     * Rewrites the answer's links to read sources as `[eN]` markers.
     *
     * @param array<string, string> $sources from {@see read()}
     *
     * @return array{report: string, passages: array<string, string>}
     */
    public static function cite(string $answer, array $sources): array
    {
        $answer = str_replace(self::LABEL, '', $answer);
        $ids = [];
        $marker = static function (string $url) use ($sources, &$ids): ?string {
            $key = CanonicalUrl::key(rtrim($url, '.,;:!?'));
            if (null === $key || !isset($sources[$key])) {
                return null;
            }
            $ids[$key] ??= 'e'.(\count($ids) + 1);

            return '['.$ids[$key].']';
        };
        $report = (string) preg_replace_callback(self::LINK, static function (array $m) use ($marker): string {
            $mark = $marker($m[2]);
            if (null === $mark) {
                return $m[0];
            }
            $label = trim($m[1]);

            return ('' !== $label ? self::LABEL.$label.self::LABEL.' ' : '').$mark;
        }, $answer);
        $report = (string) preg_replace_callback(self::AUTOLINK, static fn (array $m): string => $marker($m[1]) ?? $m[0], $report);
        $report = (string) preg_replace_callback(self::BARE, static function (array $m) use ($marker): string {
            $url = rtrim($m[0], '.,;:!?');
            $mark = $marker($url);

            return null === $mark ? $m[0] : $mark.substr($m[0], \strlen($url));
        }, $report);
        $passages = [];
        foreach ($ids as $key => $id) {
            $passages[$id] = $sources[$key];
        }

        return ['report' => $report, 'passages' => $passages];
    }

    /** The text the check judges: a link's text is left out, its marker stays. */
    public static function withoutLabels(string $text): string
    {
        return (string) preg_replace('/'.self::LABEL.'[^'.self::LABEL.']*'.self::LABEL.' ?/u', '', $text);
    }

    /** The text as the person read it, the link's text included. */
    public static function shown(string $text): string
    {
        return str_replace(self::LABEL, '', $text);
    }

    /**
     * @return array<string, string>
     */
    private static function results(string $content): array
    {
        $out = [];
        foreach (preg_split(self::RESULT_START, $content) ?: [] as $block) {
            if (1 !== preg_match('/^URL: (\S+)$/m', $block, $url)) {
                continue;
            }
            $key = CanonicalUrl::key($url[1]);
            if (null === $key) {
                continue;
            }
            $text = trim((string) preg_replace('/^URL: \S+$\n?/m', '', $block));
            $text = trim((string) preg_replace('/^\[\d+\] /', '', $text));
            $out[$key] = isset($out[$key]) ? $out[$key]."\n".$text : $text;
        }

        return $out;
    }

    /**
     * A page `web_fetch` read: "Title: …", "URL: …", a blank line, the text.
     * A failed read has no such head and gives nothing.
     *
     * @return array<string, string>
     */
    private static function page(string $content): array
    {
        if (1 !== preg_match('/\ATitle: ([^\n]*)\nURL: (\S+)\n/', $content, $head)) {
            return [];
        }
        $key = CanonicalUrl::key($head[2]);
        if (null === $key) {
            return [];
        }

        return [$key => trim($head[1]."\n".substr($content, \strlen($head[0])))];
    }
}
