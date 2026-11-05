<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

/**
 * `BSOURCE` value for an assistant installed from a template.
 * `template:{id}@{version}` fits the 64-character column.
 */
final class AgentTemplateRef
{
    public const PREFIX = 'template:';

    public static function source(string $id, string $version): string
    {
        return self::PREFIX.$id.'@'.$version;
    }

    public static function prefix(string $id): string
    {
        return self::PREFIX.$id.'@';
    }

    public static function idFromSource(string $source): ?string
    {
        if (1 !== preg_match('/^template:([a-z0-9][a-z0-9-]{0,31})@/', $source, $match)) {
            return null;
        }

        return $match[1];
    }

    public static function versionFromSource(string $source): ?string
    {
        if (1 !== preg_match('/^template:[a-z0-9][a-z0-9-]{0,31}@([0-9A-Za-z.]{1,16})$/', $source, $match)) {
            return null;
        }

        return $match[1];
    }
}
