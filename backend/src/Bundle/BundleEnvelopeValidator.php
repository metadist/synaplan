<?php

declare(strict_types=1);

namespace App\Bundle;

final class BundleEnvelopeValidator
{
    public const SCHEMA = 'synaplan-bundle.v1';
    public const MAX_DEPTH = 32;
    public const MAX_BYTES = 5_242_880;
    public const MAX_SECTIONS = 20;
    public const MAX_ITEMS = 200;

    private const ENVELOPE_KEYS = ['schema', 'createdAt', 'sourceInstance', 'sourceVersion', 'scope', 'sections'];
    private const SECTION_KEYS = ['kind', 'version', 'items'];

    /**
     * @return array{
     *   schema: string,
     *   createdAt: string,
     *   sourceInstance: string,
     *   sourceVersion: string,
     *   scope: string,
     *   sections: list<array{kind: string, version: int, items: list<array<string, mixed>>}>
     * }
     */
    public function parse(string $json, array $registeredKinds): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new BundleEnvelopeException('$', sprintf('bundle is larger than %d MB', intdiv(self::MAX_BYTES, 1024 * 1024)));
        }

        try {
            $decoded = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BundleEnvelopeException('$', 'invalid JSON: '.$e->getMessage());
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BundleEnvelopeException('$', 'envelope must be an object');
        }

        $this->rejectUnknown(array_keys($decoded), self::ENVELOPE_KEYS, '$');

        $schema = $decoded['schema'] ?? null;
        if (self::SCHEMA !== $schema) {
            throw new BundleEnvelopeException('$.schema', 'must be "'.self::SCHEMA.'"');
        }

        $createdAt = $decoded['createdAt'] ?? null;
        if (!is_string($createdAt) || !$this->isIso8601($createdAt)) {
            throw new BundleEnvelopeException('$.createdAt', 'must be an ISO-8601 date');
        }

        $sourceInstance = $decoded['sourceInstance'] ?? null;
        if (!is_string($sourceInstance) || !str_starts_with($sourceInstance, 'sha256:')) {
            throw new BundleEnvelopeException('$.sourceInstance', 'must start with sha256:');
        }

        $sourceVersion = $decoded['sourceVersion'] ?? null;
        if (!is_string($sourceVersion) || '' === trim($sourceVersion)) {
            throw new BundleEnvelopeException('$.sourceVersion', 'must be a non-empty string');
        }

        $scope = $decoded['scope'] ?? null;
        if (!is_string($scope) || null === BundleScope::tryFrom($scope)) {
            throw new BundleEnvelopeException('$.scope', 'must be user or instance');
        }

        $sections = $decoded['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections)) {
            throw new BundleEnvelopeException('$.sections', 'must be an array');
        }
        if (count($sections) > self::MAX_SECTIONS) {
            throw new BundleEnvelopeException('$.sections', 'at most '.self::MAX_SECTIONS.' sections');
        }

        $out = [];
        foreach ($sections as $i => $section) {
            $path = '$.sections['.$i.']';
            if (!is_array($section) || array_is_list($section)) {
                throw new BundleEnvelopeException($path, 'must be an object');
            }
            $this->rejectUnknown(array_keys($section), self::SECTION_KEYS, $path);
            $kind = $section['kind'] ?? null;
            if (!is_string($kind) || '' === $kind) {
                throw new BundleEnvelopeException($path.'.kind', 'required');
            }
            if ([] !== $registeredKinds && !in_array($kind, $registeredKinds, true)) {
                throw new BundleEnvelopeException($path.'.kind', 'unknown section kind');
            }
            $version = $section['version'] ?? null;
            if (!is_int($version) && !(is_float($version) && $version == (int) $version)) {
                throw new BundleEnvelopeException($path.'.version', 'must be an integer');
            }
            $items = $section['items'] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                throw new BundleEnvelopeException($path.'.items', 'must be an array');
            }
            if (count($items) > self::MAX_ITEMS) {
                throw new BundleEnvelopeException($path.'.items', 'at most '.self::MAX_ITEMS.' items');
            }
            $normalizedItems = [];
            foreach ($items as $j => $item) {
                if (!is_array($item) || array_is_list($item)) {
                    throw new BundleEnvelopeException($path.'.items['.$j.']', 'must be an object');
                }
                /* @var array<string, mixed> $item */
                $normalizedItems[] = $item;
            }
            $out[] = [
                'kind' => $kind,
                'version' => (int) $version,
                'items' => $normalizedItems,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'createdAt' => $createdAt,
            'sourceInstance' => $sourceInstance,
            'sourceVersion' => $sourceVersion,
            'scope' => $scope,
            'sections' => $out,
        ];
    }

    private function isIso8601(string $value): bool
    {
        foreach ([\DateTimeInterface::ATOM, \DateTimeInterface::RFC3339, 'Y-m-d\TH:i:s\Z'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed instanceof \DateTimeImmutable) {
                return true;
            }
        }
        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @param list<string|int> $keys
     * @param list<string>     $allowed
     */
    private function rejectUnknown(array $keys, array $allowed, string $path): void
    {
        foreach ($keys as $key) {
            $name = (string) $key;
            if (!in_array($name, $allowed, true)) {
                throw new BundleEnvelopeException($path.'.'.$name, 'unknown key');
            }
        }
    }
}
