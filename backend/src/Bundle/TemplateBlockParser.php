<?php

declare(strict_types=1);

namespace App\Bundle;

/**
 * Optional `template` block on a synaplan-bundle.v1 envelope.
 */
final class TemplateBlockParser
{
    public const KINDS = ['capability', 'knowledgeFolder', 'connection', 'tool', 'feature', 'watch'];

    private const KEYS = ['id', 'version', 'titleKey', 'summaryKey', 'category', 'requires', 'tryIt'];
    private const REQUIREMENT_KEYS = ['kind', 'value', 'hintKey', 'optional'];

    /**
     * @return array{
     *   id: string,
     *   version: string,
     *   titleKey: string,
     *   summaryKey: string,
     *   category: string,
     *   requires: list<array{kind: string, value: string|null, hintKey: string|null, optional: bool}>,
     *   tryIt: list<string>
     * }
     */
    public function parse(mixed $template): array
    {
        if (!is_array($template) || array_is_list($template)) {
            throw new BundleEnvelopeException('$.template', 'must be an object');
        }
        $this->rejectUnknown(array_keys($template), self::KEYS, '$.template');

        return [
            'id' => $this->id($template['id'] ?? null),
            'version' => $this->version($template['version'] ?? null),
            'titleKey' => $this->key($template['titleKey'] ?? null, '$.template.titleKey'),
            'summaryKey' => $this->key($template['summaryKey'] ?? null, '$.template.summaryKey'),
            'category' => $this->category($template['category'] ?? null),
            'requires' => $this->requires($template['requires'] ?? null),
            'tryIt' => $this->tryIt($template['tryIt'] ?? null),
        ];
    }

    private function id(mixed $id): string
    {
        if (!is_string($id) || 1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id)) {
            throw new BundleEnvelopeException('$.template.id', 'must match [a-z0-9][a-z0-9-]{0,31}');
        }

        return $id;
    }

    private function version(mixed $version): string
    {
        if (!is_string($version) || 1 !== preg_match('/^[0-9A-Za-z.]{1,16}$/', $version)) {
            throw new BundleEnvelopeException('$.template.version', 'must be 1 to 16 letters, digits or dots');
        }

        return $version;
    }

    private function key(mixed $value, string $path): string
    {
        if (!is_string($value) || 1 !== preg_match('/^[a-zA-Z][a-zA-Z0-9.]{0,80}$/', $value)) {
            throw new BundleEnvelopeException($path, 'must be an i18n key');
        }

        return $value;
    }

    private function category(mixed $category): string
    {
        if (!is_string($category) || 1 !== preg_match('/^[a-z][a-z-]{0,24}$/', $category)) {
            throw new BundleEnvelopeException('$.template.category', 'must be a short category id');
        }

        return $category;
    }

    /**
     * @return list<array{kind: string, value: string|null, hintKey: string|null, optional: bool}>
     */
    private function requires(mixed $requires): array
    {
        if (!is_array($requires) || !array_is_list($requires)) {
            throw new BundleEnvelopeException('$.template.requires', 'must be an array');
        }
        if (count($requires) > 12) {
            throw new BundleEnvelopeException('$.template.requires', 'at most 12 requirements');
        }
        $out = [];
        foreach ($requires as $i => $row) {
            $path = '$.template.requires['.$i.']';
            if (!is_array($row) || array_is_list($row)) {
                throw new BundleEnvelopeException($path, 'must be an object');
            }
            $this->rejectUnknown(array_keys($row), self::REQUIREMENT_KEYS, $path);
            $kind = $row['kind'] ?? null;
            if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
                throw new BundleEnvelopeException($path.'.kind', 'unknown requirement kind');
            }
            $value = $row['value'] ?? null;
            if (null !== $value && (!is_string($value) || '' === $value || strlen($value) > 64)) {
                throw new BundleEnvelopeException($path.'.value', 'must be a short string');
            }
            $hintKey = $row['hintKey'] ?? null;
            if (null !== $hintKey) {
                $hintKey = $this->key($hintKey, $path.'.hintKey');
            }
            $optional = $row['optional'] ?? false;
            if (!is_bool($optional)) {
                throw new BundleEnvelopeException($path.'.optional', 'must be true or false');
            }
            $out[] = [
                'kind' => $kind,
                'value' => $value,
                'hintKey' => $hintKey,
                'optional' => $optional,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function tryIt(mixed $tryIt): array
    {
        if (!is_array($tryIt) || !array_is_list($tryIt) || count($tryIt) < 1 || count($tryIt) > 4) {
            throw new BundleEnvelopeException('$.template.tryIt', 'must be 1 to 4 i18n keys');
        }
        $out = [];
        foreach ($tryIt as $i => $key) {
            $out[] = $this->key($key, '$.template.tryIt['.$i.']');
        }

        return $out;
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
