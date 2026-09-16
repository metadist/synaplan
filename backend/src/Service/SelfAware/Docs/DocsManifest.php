<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Docs;

/**
 * Machine-readable export published by synaplan-docs (`synaplan-docs-manifest/1`).
 */
final readonly class DocsManifest
{
    public const SCHEMA_ID = 'synaplan-docs-manifest/1';

    private const SLUG_PATTERN = '/^[a-z0-9-]{1,64}$/';

    /**
     * @param list<DocsPage> $pages
     */
    public function __construct(
        public string $schema,
        public string $generatedAt,
        public string $siteUrl,
        public string $version,
        public array $pages,
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Docs manifest is not valid JSON: '.$e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Docs manifest must be a JSON object.');
        }

        $schema = (string) ($data['schema'] ?? '');
        if (self::SCHEMA_ID !== $schema) {
            throw new \InvalidArgumentException(sprintf('Unsupported docs manifest schema "%s".', $schema));
        }

        $siteUrl = rtrim((string) ($data['site_url'] ?? ''), '/');
        $siteHost = self::hostOf($siteUrl.'/', 'site_url');

        $pages = [];
        foreach ($data['pages'] ?? [] as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Each docs manifest page must be an object.');
            }
            $pages[] = self::pageFromArray($row, $siteHost);
        }

        return new self(
            $schema,
            (string) ($data['generated_at'] ?? ''),
            $siteUrl,
            (string) ($data['version'] ?? ''),
            $pages,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function pageFromArray(array $row, string $siteHost): DocsPage
    {
        $slug = (string) ($row['slug'] ?? '');
        $url = (string) ($row['url'] ?? '');
        $rawUrl = (string) ($row['raw_url'] ?? '');
        $sha256 = (string) ($row['sha256'] ?? '');
        if ('' === $slug || '' === $url || '' === $rawUrl || '' === $sha256) {
            throw new \InvalidArgumentException('Docs manifest page is missing slug, url, raw_url or sha256.');
        }
        if (1 !== preg_match(self::SLUG_PATTERN, $slug)) {
            throw new \InvalidArgumentException(sprintf('Invalid docs slug "%s".', $slug));
        }
        if (self::hostOf($url, 'url') !== $siteHost) {
            throw new \InvalidArgumentException(sprintf('url for slug "%s" is not on the manifest host.', $slug));
        }
        if (self::hostOf($rawUrl, 'raw_url') !== $siteHost) {
            throw new \InvalidArgumentException(sprintf('raw_url for slug "%s" is not on the manifest host.', $slug));
        }

        return new DocsPage(
            $slug,
            (string) ($row['title'] ?? $slug),
            (string) ($row['section'] ?? ''),
            (string) ($row['description'] ?? ''),
            $url,
            $rawUrl,
            $sha256,
            (int) ($row['bytes'] ?? 0),
            (string) ($row['updated_at'] ?? ''),
        );
    }

    private static function hostOf(string $url, string $field): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($host) || '' === $host || 'https' !== $scheme) {
            throw new \InvalidArgumentException(sprintf('Docs manifest %s must be an https URL.', $field));
        }

        return strtolower($host);
    }
}
