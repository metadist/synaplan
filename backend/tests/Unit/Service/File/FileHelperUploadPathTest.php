<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * BFILES.BFILEPATH and node file descriptors do not agree on one shape: some
 * rows hold the raw upload-dir-relative path, others the public serve URL with
 * or without scheme and host. Prefixing the upload dir on top of a serve URL
 * never resolves, which is how a generated image stayed invisible to vision
 * (#1596), so this reduction is pinned here.
 */
final class FileHelperUploadPathTest extends TestCase
{
    #[DataProvider('paths')]
    public function testNormalizeUploadRelativePath(string $stored, string $expected): void
    {
        $this->assertSame($expected, FileHelper::normalizeUploadRelativePath($stored));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function paths(): iterable
    {
        yield 'already relative' => [
            '01/000/00001/2026/07/cat.png',
            '01/000/00001/2026/07/cat.png',
        ];

        yield 'leading slash is dropped' => [
            '/01/000/00001/2026/07/cat.png',
            '01/000/00001/2026/07/cat.png',
        ];

        yield 'serve prefix is stripped' => [
            '/api/v1/files/uploads/01/000/00001/2026/07/cat.png',
            '01/000/00001/2026/07/cat.png',
        ];

        yield 'serve prefix without leading slash is stripped' => [
            'api/v1/files/uploads/cat.png',
            'cat.png',
        ];

        yield 'absolute serve url loses scheme, host and prefix' => [
            'https://app.example.com/api/v1/files/uploads/2026/07/cat.png',
            '2026/07/cat.png',
        ];

        yield 'only the outermost serve prefix is stripped' => [
            '/api/v1/files/uploads/api/v1/files/uploads/cat.png',
            'api/v1/files/uploads/cat.png',
        ];

        yield 'empty string stays empty' => ['', ''];

        /*
         * Traversal is NOT neutralised here on purpose: this is pure string
         * work and FileHelper::resolvePathNfs() rejects the result against the
         * upload root. Pinned so nobody mistakes it for a sanitizer.
         */
        yield 'traversal survives for the resolver to reject' => [
            'https://evil.example.com/api/v1/files/uploads/../../etc/passwd',
            '../../etc/passwd',
        ];
    }
}
