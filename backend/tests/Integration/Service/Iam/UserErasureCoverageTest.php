<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Iam;

use App\Service\Iam\Erasure\UserDataEraserInterface;
use App\Service\Iam\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Account deletion runs one eraser per domain. The order is pinned because
 * some erasers delete immediately while entity removals wait for the flush;
 * the coverage check makes a user-owned table that no eraser removes fail CI
 * instead of surviving the account.
 */
final class UserErasureCoverageTest extends KernelTestCase
{
    private const ORDER = [
        'AccessCredentialsEraser', 'ConnectionEraser', 'KnowledgeEraser', 'DesktopEraser',
        'UsageLogEraser', 'WidgetEraser', 'ShareEraser', 'AgentEraser',
        'ApprovalEraser', 'SavedTaskEraser', 'CloudWatchEraser', 'UrlWatchEraser', 'NotionEraser', 'ConversationEraser',
        'VoiceSessionEraser', 'FileEraser', 'ComputeEraser', 'InboxEraser',
        'ConfigEraser', 'PromptEraser', 'AccountRecordsEraser', 'SignupCodeUseEraser',
        'MemoryEraser', 'IdentityEraser', 'CredentialEraser', 'ResearchEraser',
    ];

    public function testErasersRunInTheEstablishedOrder(): void
    {
        self::assertSame(self::ORDER, array_map(
            static fn (UserDataEraserInterface $eraser): string => (new \ReflectionClass($eraser))->getShortName(),
            $this->erasers(),
        ));
    }

    public function testEveryUserOwnedEntityIsErasedWithItsAccount(): void
    {
        $covered = [];
        foreach ($this->erasers() as $eraser) {
            array_push($covered, ...$eraser::covers());
        }

        $notErased = array_values(array_diff(
            array_keys(UserOwnedTables::in(static::getContainer()->get(EntityManagerInterface::class))),
            $covered,
        ));

        self::assertSame([], $notErased, 'A user-owned entity is not erased with its account. Name it in an eraser\'s covers().');
    }

    /**
     * @return list<UserDataEraserInterface>
     */
    private function erasers(): array
    {
        $service = static::getContainer()->get(UserDeletionService::class);
        $erasers = (new \ReflectionProperty(UserDeletionService::class, 'erasers'))->getValue($service);
        self::assertIsIterable($erasers);

        return array_values([...$erasers]);
    }
}
