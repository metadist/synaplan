<?php

declare(strict_types=1);

namespace App\Service\Research\Export;

use App\Entity\Message;
use App\Entity\SearchResult;
use App\Repository\SearchResultRepository;
use App\Service\File\DocumentGeneratorService;
use App\Service\Research\Run\ReaderReport;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A reply as a file the person keeps: the question (or the reply's own
 * top heading) as title, the reply with its tables, and the numbered list
 * of the sources it cites.
 *
 * Built from the saved message, not from the research run, which expires:
 * the sources come from the question the reply answers, where the chat
 * stores them too.
 */
final readonly class ResearchReplyExporter
{
    private const DOMAIN = 'research';
    private const TITLE_LIMIT = 150;
    private const FILE_NAME_LIMIT = 80;
    private const FALLBACK_FILE_NAME = 'research';
    private const TOP_HEADING = '/^#[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*$/u';

    public function __construct(
        private SearchResultRepository $searchResults,
        private DocumentGeneratorService $documents,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws \RuntimeException when the Word file cannot be written
     */
    public function export(Message $reply, ReplyExportFormat $format, string $locale): ExportedReply
    {
        $question = $this->searchResults->findQuestionFor($reply);
        [$title, $markdown] = $this->document($reply, $question, $locale);
        $content = match ($format) {
            ReplyExportFormat::Markdown => $markdown."\n",
            ReplyExportFormat::Html => ReplyHtmlPage::render($title, $markdown, $locale),
            ReplyExportFormat::Docx => $this->docx($markdown),
        };
        $name = self::fileName($title);

        return new ExportedReply(
            $content,
            $format->contentType(),
            $name.'.'.$format->value,
            self::asciiFileName($name, $locale).'.'.$format->value,
        );
    }

    /**
     * @return array{0: string, 1: string} the title and the whole Markdown document
     */
    private function document(Message $reply, ?Message $question, string $locale): array
    {
        $text = trim($reply->getText());
        $firstLine = strtok($text, "\n");
        if (false !== $firstLine && 1 === preg_match(self::TOP_HEADING, rtrim($firstLine), $match)) {
            $title = self::plain($match[1]);
            $body = $text;
        } else {
            $title = $this->questionTitle($question, $locale);
            $body = '# '.$title."\n\n".$text;
        }

        $sources = null !== $question ? $this->sources($question) : [];
        $markdown = ReaderReport::withSourceList(
            $body,
            $sources,
            $this->translator->trans('research.report.sources', [], self::DOMAIN, $locale),
        );

        return [$title, $markdown];
    }

    private function questionTitle(?Message $question, string $locale): string
    {
        $text = null !== $question ? trim($question->getText()) : '';
        $firstLine = '' !== $text ? trim((string) strtok($text, "\n")) : '';
        if ('' === $firstLine) {
            return $this->translator->trans('research.report.untitled', [], self::DOMAIN, $locale);
        }

        return mb_strlen($firstLine) > self::TITLE_LIMIT
            ? rtrim(mb_substr($firstLine, 0, self::TITLE_LIMIT - 1)).'…'
            : $firstLine;
    }

    /**
     * @return list<array{title: string, url: string, host: string, description: string}>
     */
    private function sources(Message $question): array
    {
        return array_values(array_map(static function (SearchResult $result): array {
            $url = trim($result->getUrl());
            $host = trim((string) $result->getSource());
            $title = self::plain($result->getTitle());

            return [
                'title' => '' !== $title ? $title : ('' !== $host ? $host : $url),
                'url' => $url,
                'host' => $host,
                'description' => (string) $result->getDescription(),
            ];
        }, $this->searchResults->findByMessage($question)));
    }

    private function docx(string $markdown): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reply-export-');
        if (false === $path) {
            throw new \RuntimeException('No temporary file for the Word export');
        }
        try {
            $this->documents->write(ExportMarkdown::forDocument($markdown), 'docx', $path);
            $content = file_get_contents($path);
            if (false === $content || '' === $content) {
                throw new \RuntimeException('The Word export could not be read back');
            }

            return $content;
        } finally {
            @unlink($path);
        }
    }

    /** A title or source name on one line, without the emphasis marks around it. */
    private static function plain(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim((string) preg_replace('/(\*\*|__|\*|`)(.+?)\1/u', '$2', $text));
    }

    private static function fileName(string $title): string
    {
        $slug = trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', mb_strtolower($title)), '-');
        $slug = rtrim(mb_substr($slug, 0, self::FILE_NAME_LIMIT), '-');

        return '' !== $slug ? $slug : self::FALLBACK_FILE_NAME;
    }

    private static function asciiFileName(string $name, string $locale): string
    {
        $slug = (new AsciiSlugger($locale))->slug($name)->lower()->toString();
        $slug = trim(substr($slug, 0, self::FILE_NAME_LIMIT), '-');

        return '' !== $slug ? $slug : self::FALLBACK_FILE_NAME;
    }
}
