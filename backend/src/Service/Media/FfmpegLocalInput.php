<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Input options for an ffmpeg call that reads a file someone uploaded.
 *
 * ffmpeg picks the demuxer by content, not by extension, so a playlist saved
 * as `.mp4` could otherwise make it fetch URLs or read through other
 * protocols. With the whitelist the input can only be read from the local
 * file system. The options go directly before `-i`.
 */
final class FfmpegLocalInput
{
    /** @var list<string> */
    public const OPTIONS = ['-protocol_whitelist', self::PROTOCOLS];

    public const PROTOCOLS = 'file';

    /** The same options for a command line built as one string. */
    public const SHELL_OPTIONS = '-protocol_whitelist '.self::PROTOCOLS;
}
