<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\PromptRepository;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AssistantKind;
use App\Service\Iam\SharedResourceIds;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PromptRepositorySharedTest extends KernelTestCase
{
    public function testNoSharesYieldsLegacySql(): void
    {
        self::bootKernel();
        $shared = $this->createMock(SharedResourceIds::class);
        $shared->expects(self::atLeastOnce())
            ->method('forUser')
            ->with(self::identicalTo(3), AssistantKind::KEY, Permission::Use)
            ->willReturn([]);

        $repo = new PromptRepository(
            static::getContainer()->get('doctrine'),
            $shared,
        );

        $prompts = $repo->findAllForUser(3);

        foreach ($prompts as $prompt) {
            self::assertContains($prompt->getOwnerId(), [0, 3]);
        }
    }
}
