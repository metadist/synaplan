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
}
