<?php

namespace App\Tests\Service\File;

use App\Service\File\UserUploadPathBuilder;
use PHPUnit\Framework\TestCase;

class UserUploadPathBuilderTest extends TestCase
{
    public function testBuildUserBaseRelativePathPadsToFiveDigits(): void
    {
        $builder = new UserUploadPathBuilder();

        $this->assertSame('13/000/00013', $builder->buildUserBaseRelativePath(13));
        $this->assertSame('09/008/00809', $builder->buildUserBaseRelativePath(809));
    }

    public function testBuildUserBaseRelativePathSupportsLargeIds(): void
    {
        $builder = new UserUploadPathBuilder();

        $this->assertSame('67/345/1234567', $builder->buildUserBaseRelativePath(1234567));
    }

    public function testBuildUserBaseRelativePathBoundaryConditions(): void
    {
        $builder = new UserUploadPathBuilder();

        // Minimum case with maximum padding
        $this->assertSame('01/000/00001', $builder->buildUserBaseRelativePath(1));

        // Edge case: user ID 0
        $this->assertSame('00/000/00000', $builder->buildUserBaseRelativePath(0));

        // Exactly 5 digits, no padding needed
        $this->assertSame('99/999/99999', $builder->buildUserBaseRelativePath(99999));

        // First 6-digit ID
        $this->assertSame('00/000/100000', $builder->buildUserBaseRelativePath(100000));
    }
}
