<?php

declare(strict_types=1);

namespace App\Service\MarketingNews;

/**
 * Parses RSS 2.0 feeds (WordPress-compatible namespaces) into normalized news items.
 */
final class RssFeedParser
{
    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    private const NS_MEDIA = 'http://search.yahoo.com/mrss/';
    private const MAX_EXCERPT_LENGTH = 280;
    private const MAX_TAGS = 3;

    /**
     * @return list<array{title: string, url: string, excerpt: string, imageUrl: string|null, publishedAt: string|null, tags: list<string>}>
     */
    public function parse(string $xmlBody): array
    {
        if ('' === trim($xmlBody)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlBody, \SimpleXMLElement::class, \LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (false === $xml || !isset($xml->channel)) {
            return [];
        }

        $items = [];
        foreach ($xml->channel->item ?? [] as $item) {
            $parsed = $this->parseItem($item);
            if (null !== $parsed) {
                $items[] = $parsed;
            }
        }

        return $items;
    }

    /**
     * @return array{title: string, url: string, excerpt: string, imageUrl: string|null, publishedAt: string|null, tags: list<string>}|null
     */
    private function parseItem(\SimpleXMLElement $item): ?array
    {
        $title = trim((string) ($item->title ?? ''));
        $url = trim((string) ($item->link ?? ''));
        if ('' === $title || !$this->isValidUrl($url)) {
            return null;
        }

        $excerpt = trim(strip_tags((string) ($item->description ?? '')));
        if ('' === $excerpt) {
            $contentEncoded = $item->children(self::NS_CONTENT)->encoded ?? null;
            if (null !== $contentEncoded) {
                $excerpt = trim(strip_tags((string) $contentEncoded));
            }
        }
        if (mb_strlen($excerpt) > self::MAX_EXCERPT_LENGTH) {
            $excerpt = mb_substr($excerpt, 0, self::MAX_EXCERPT_LENGTH - 1).'…';
        }

        $tags = [];
        foreach ($item->category ?? [] as $category) {
            $tag = trim((string) $category);
            if ('' !== $tag) {
                $tags[] = $tag;
            }
        }

        return [
            'title' => $title,
            'url' => $url,
            'excerpt' => $excerpt,
            'imageUrl' => $this->extractImageUrl($item),
            'publishedAt' => $this->parsePubDate((string) ($item->pubDate ?? '')),
            'tags' => \array_slice($tags, 0, self::MAX_TAGS),
        ];
    }

    private function extractImageUrl(\SimpleXMLElement $item): ?string
    {
        $enclosure = $item->enclosure ?? null;
        if (null !== $enclosure) {
            $url = trim((string) ($enclosure['url'] ?? ''));
            if ($this->isValidUrl($url)) {
                return $url;
            }
        }

        $media = $item->children(self::NS_MEDIA)->content ?? null;
        if (null !== $media) {
            $url = trim((string) ($media['url'] ?? ''));
            if ($this->isValidUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    private function isValidUrl(string $url): bool
    {
        if ('' === $url || !filter_var($url, \FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, \PHP_URL_SCHEME);

        return \in_array($scheme, ['http', 'https'], true);
    }

    private function parsePubDate(string $pubDate): ?string
    {
        if ('' === trim($pubDate)) {
            return null;
        }

        $timestamp = strtotime($pubDate);
        if (false === $timestamp) {
            return null;
        }

        return gmdate('c', $timestamp);
    }
}
