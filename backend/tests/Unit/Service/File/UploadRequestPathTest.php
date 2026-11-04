<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\UploadRequestPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `og/` and `widget-icons/` are served without an access check, so whether a
 * request path is inside one of them must be decided on a path that cannot
 * leave the directory it names.
 */
final class UploadRequestPathTest extends TestCase
{
    #[DataProvider('canonicalPaths')]
    public function testAcceptsACanonicalPath(string $path): void
    {
        $this->assertSame($path, UploadRequestPath::fromRoute($path)?->relative);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function canonicalPaths(): iterable
    {
        yield 'user upload' => ['02/000/00002/2026/11/report_1793761299_6a4377a4.pdf'];
        yield 'OG preview' => ['og/12/0a1b2c3d4e5f60718293a4b5.jpg'];
        yield 'widget icon' => ['widget-icons/02/000/00002/2026/11/wicon_6ca85a9cb24f0c71_1793774222.png'];
        yield 'dots inside a name are not a segment' => ['02/000/00002/2026/11/v1..2.tar.gz'];
        yield 'a name starting with a dot' => ['02/000/00002/2026/11/.notes.txt'];
    }

    #[DataProvider('pathsThatCouldLeaveTheirDirectory')]
    public function testRefusesAPathThatCouldLeaveItsDirectory(string $path): void
    {
        $this->assertNull(UploadRequestPath::fromRoute($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsThatCouldLeaveTheirDirectory(): iterable
    {
        yield 'widget icon prefix climbing out' => ['widget-icons/../02/000/00002/2026/11/private.txt'];
        yield 'OG prefix climbing out' => ['og/../02/000/00002/2026/11/private.txt'];
        yield 'current-directory segment' => ['widget-icons/./../02/000/00002/2026/11/private.txt'];
        yield 'trailing parent segment' => ['widget-icons/..'];
        yield 'leading parent segment' => ['../etc/passwd'];
        yield 'NUL byte' => ["og/12/x.jpg\0.txt"];
    }

    #[DataProvider('publicAssets')]
    public function testOnlyTheWholeFirstSegmentMakesAPathPublic(string $path, bool $public): void
    {
        $this->assertSame($public, UploadRequestPath::fromRoute($path)?->isPublicAsset());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function publicAssets(): iterable
    {
        yield 'OG preview' => ['og/12/x.jpg', true];
        yield 'widget icon' => ['widget-icons/02/000/00002/2026/11/wicon_x.png', true];
        yield 'user upload' => ['02/000/00002/2026/11/x.png', false];
        yield 'a directory that only starts like a public one' => ['ogre/x.png', false];
        yield 'a public directory name deeper in the path' => ['02/000/00002/og/x.png', false];
        yield 'the bare directory name' => ['og', false];
    }
}
