<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * How the fixture names files and versions. `keyed` looks like Nextcloud
 * (numeric file ids, bare etags); `sso` looks like OpenCloud
 * (`storage$space!node` ids, quoted etags), so the core is tested against
 * both shapes before either app speaks the protocol.
 */
final class Profile
{
    public static function remoteId(string $profile, string $person, string $path): string
    {
        if ('sso' === $profile) {
            return 'fixture-storage$'.substr(sha1('space:'.$person), 0, 12).'!'.substr(sha1($person.'/'.$path), 0, 20);
        }

        return (string) (crc32($person.'/'.$path) & 0x7FFFFFFF);
    }

    public static function version(string $profile, string $bytes): string
    {
        $etag = md5($bytes);

        return 'sso' === $profile ? '"'.$etag.'"' : $etag;
    }
}
