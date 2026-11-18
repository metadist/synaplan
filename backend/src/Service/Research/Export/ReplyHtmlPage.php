<?php

declare(strict_types=1);

namespace App\Service\Research\Export;

/**
 * One self-contained, printable web page of a reply: no script, nothing
 * loaded from elsewhere, readable in light and dark and on paper.
 *
 * Parsedown runs in safe mode, so raw HTML from the model or a source title
 * is shown as text, and the page's own policy forbids scripts and outside
 * resources as a second line of defence.
 */
final class ReplyHtmlPage
{
    private const POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'";

    private const STYLE = <<<'CSS'
        :root { color-scheme: light dark; --ink: #1f2328; --muted: #57606a; --line: #d0d7de; --paper: #ffffff; --head: #f6f8fa; --link: #0a58ca; }
        @media (prefers-color-scheme: dark) { :root { --ink: #e6edf3; --muted: #9da7b3; --line: #3d444d; --paper: #0d1117; --head: #161b22; --link: #6cb6ff; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--paper); color: var(--ink); font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        main { max-width: 46rem; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; overflow-wrap: anywhere; }
        h1, h2, h3, h4 { line-height: 1.25; margin: 1.6em 0 0.6em; }
        h1 { font-size: 1.9rem; margin-top: 0; }
        a { color: var(--link); }
        blockquote { margin: 1em 0; padding: 0.25em 1em; border-left: 4px solid var(--line); color: var(--muted); }
        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.9em; }
        pre { padding: 1em; overflow-x: auto; background: var(--head); border: 1px solid var(--line); border-radius: 6px; white-space: pre-wrap; }
        table { width: 100%; border-collapse: collapse; margin: 1em 0; display: block; overflow-x: auto; }
        th, td { border: 1px solid var(--line); padding: 0.45em 0.75em; text-align: left; vertical-align: top; }
        th { background: var(--head); }
        hr { border: 0; border-top: 1px solid var(--line); margin: 2em 0; }
        @media print {
            :root { --ink: #000000; --muted: #333333; --line: #999999; --paper: #ffffff; --head: #eeeeee; --link: #000000; }
            main { max-width: none; padding: 0; }
            table { display: table; }
            tr, pre, blockquote { break-inside: avoid; }
            a[href^="http"]::after { content: " (" attr(href) ")"; font-size: 0.85em; color: var(--muted); }
        }
        CSS;

    public static function render(string $title, string $markdown, string $locale): string
    {
        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(true);
        $body = $parsedown->text(ExportMarkdown::forPage($markdown));
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return '<!DOCTYPE html>'."\n"
            .'<html lang="'.$escape($locale).'">'."\n"
            .'<head>'."\n"
            .'<meta charset="utf-8">'."\n"
            .'<meta http-equiv="Content-Security-Policy" content="'.self::POLICY.'">'."\n"
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'."\n"
            .'<meta name="referrer" content="no-referrer">'."\n"
            .'<title>'.$escape($title).'</title>'."\n"
            .'<style>'.self::STYLE.'</style>'."\n"
            .'</head>'."\n"
            .'<body>'."\n"
            .'<main>'."\n".$body."\n".'</main>'."\n"
            .'</body>'."\n"
            .'</html>'."\n";
    }
}
