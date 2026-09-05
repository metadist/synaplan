<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\Prompt;
use App\Repository\PromptRepository;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AssistantKind;
use PHPUnit\Framework\TestCase;

final class AssistantKindTest extends TestCase
{
    public function testSystemPromptOwnerIdIsZeroAndNeverShareableViaPermissions(): void
    {
        $prompt = new Prompt();
        $prompt->setOwnerId(0);
        $prompt->setTopic('general');
        $prompt->setShortDescription('General');
        $prompt->setPrompt('You are helpful.');
        (new \ReflectionProperty(Prompt::class, 'id'))->setValue($prompt, 4);

        $repo = $this->createMock(PromptRepository::class);
        $repo->expects(self::atLeastOnce())
            ->method('find')
            ->with(4)
            ->willReturn($prompt);
        $kind = new AssistantKind($repo);

        self::assertSame(AssistantKind::KEY, $kind->key());
        self::assertSame(0, $kind->ownerId('4'));
        self::assertSame(
            [Permission::Read, Permission::Use, Permission::Edit],
            $kind->supportedPermissions(),
        );
        self::assertSame('TASKPROMPT:sales', AssistantKind::knowledgeFolder('sales'));
    }
}
