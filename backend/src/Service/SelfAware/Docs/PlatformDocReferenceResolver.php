<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Docs;

use Psr\Log\LoggerInterface;

/**
 * Turns `[Doc:slug]` citations into Markdown links for channels that cannot
 * render the web chat's doc pill. The slug is looked up in the synced docs
 * catalog. An unknown slug or an unsafe URL is removed, so a person never
 * sees a raw tag.
 */
final readonly class PlatformDocReferenceResolver
{
    /**
     * The tag plus the spaces touching it. Cleanup stays inside this match,
     * so a removed citation cannot restyle a code block elsewhere.
     */
    private const TAG_PATTERN = '/[ \t]*\[Doc\s*:\s*([a-z0-9-]+(?:\s*,\s*[a-z0-9-]+)*)\.{0,3}\][ \t]*/i';

    public function __construct(
        private PlatformDocsSyncState $state,
        private LoggerInterface $logger,
    ) {
    }

    public function resolveDocTags(string $text): string
    {
        if (false === stripos($text, '[doc')) {
            return $text;
        }

        $pages = $this->catalog();
        $stripped = false;
        $resolved = (string) preg_replace_callback(
            self::TAG_PATTERN,
            function (array $matches) use ($pages, &$stripped): string {
                $links = [];
                foreach ($this->slugs($matches[1]) as $slug) {
                    $link = $this->linkFor($slug, $pages);
                    if (null !== $link) {
                        $links[] = $link;
                        continue;
                    }
                    $stripped = true;
                    $this->logger->debug('Doc reference tag could not be resolved, stripping', [
                        'slug' => $slug,
                    ]);
                }

                if ([] === $links) {
                    // Between two words, keep one space. Before punctuation or
                    // at the edge, the spaces around the tag go away with it.
                    $betweenWords = 1 === preg_match('/^[ \t]/', $matches[0])
                        && 1 === preg_match('/[ \t]$/', $matches[0]);

                    return $betweenWords ? ' ' : '';
                }

                preg_match('/^[ \t]*/', $matches[0], $lead);
                preg_match('/[ \t]*$/', $matches[0], $trail);

                return $lead[0].implode(' ', $links).$trail[0];
            },
            $text,
        );

        if ($stripped) {
            $resolved = (string) preg_replace('/\A[ \t]+|[ \t]+\z/', '', $resolved);
        }

        return $resolved;
    }

    /**
     * Same `docs` meta the web chat stores, so a channel reply opened in the
     * app can still render the pill from the `[Doc:slug]` left in the text.
     *
     * @param array<string, mixed> $metadata
     */
    public static function encodeDocsMeta(array $metadata): ?string
    {
        $docs = $metadata['docs'] ?? null;
        if (!is_array($docs) || [] === $docs) {
            return null;
        }

        $encoded = json_encode($docs, \JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }

    /**
     * @return array<string, array{title: string, url: string}>
     */
    private function catalog(): array
    {
        $index = [];
        foreach ($this->state->read()['pages'] as $slug => $page) {
            $index[strtolower($slug)] = [
                'title' => $page['title'],
                'url' => $page['url'],
            ];
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    private function slugs(string $list): array
    {
        $slugs = [];
        foreach (preg_split('/\s*,\s*/', $list) ?: [] as $slug) {
            $slug = strtolower(trim($slug));
            if ('' !== $slug) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @param array<string, array{title: string, url: string}> $pages
     */
    private function linkFor(string $slug, array $pages): ?string
    {
        $page = $pages[$slug] ?? null;
        if (null === $page || 1 !== preg_match('#^https://[^\s)"\'<>]+$#i', $page['url'])) {
            return null;
        }

        return '['.$this->linkTitle($page['title'], $slug).']('.$page['url'].')';
    }

    private function linkTitle(string $title, string $slug): string
    {
        $title = trim((string) preg_replace('/\s+/', ' ', str_replace(['[', ']', '(', ')'], ' ', $title)));

        return '' !== $title ? $title : $slug;
    }
}
