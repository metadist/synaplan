<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;

final class AgentVersionRepositoryImmutabilityTest extends TestCase
{
    public function testRepositoryNeverUpdatesAndOnlyDeletesPerAgent(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Repository/AgentVersionRepository.php');
        self::assertStringNotContainsString('->update(', $src);
        self::assertSame(1, substr_count($src, '->delete()'));
        self::assertStringContainsString('deleteForAgent', $src);
    }
}
