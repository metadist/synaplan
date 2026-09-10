<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * Shared Markdown → HTML conversion for outbound mail.
 *
 * Safe-mode Parsedown (no raw HTML from the model). Callers that still need
 * to append a footer use {@see toFragment()} then {@see wrapDocument()}.
 */
final readonly class MarkdownEmailFormatter
{
    public function toFragment(string $markdown): string
    {
        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(true);

        return $parsedown->text($markdown);
    }

    public function wrapDocument(string $html): string
    {
        return '<!DOCTYPE html><html><body>'.$html.'</body></html>';
    }

    public function toHtml(string $markdown): string
    {
        return $this->wrapDocument($this->toFragment($markdown));
    }
}
