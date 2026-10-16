<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Iam;

use App\Entity\Approval;
use App\Entity\ChatSummary;
use App\Entity\DocumentRevision;
use App\Entity\UrlWatch;
use App\Entity\VoiceSession;
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
        'AccessCredentialsEraser', 'ConnectionEraser', 'KnowledgeEraser', 'DesktopEraser', 'UsageLogEraser', 'WidgetEraser',
        'ShareEraser', 'AgentEraser', 'SavedTaskEraser', 'ConversationEraser', 'FileEraser',
        'ComputeEraser', 'InboxEraser', 'ConfigEraser', 'PromptEraser', 'AccountRecordsEraser', 'MemoryEraser',
        'IdentityEraser', 'CredentialEraser',
    ];

    /**
     * User-owned tables that account deletion does not erase yet: no eraser,
     * no foreign key with ON DELETE CASCADE, no listener (found 2026-10-15,
     * code cleanup V3). The list may only shrink — erase a table, delete its
     * entry here. {@see UserErasureLeavesNoRowTest} expects exactly these
     * tables to keep a row.
     */
    public const NOT_ERASED_YET = [
        Approval::class,
        ChatSummary::class,
        DocumentRevision::class,
        UrlWatch::class,
        VoiceSession::class,
    ];

    public function testErasersRunInTheEstablishedOrder(): void
    {
        self::assertSame(self::ORDER, array_map(
            static fn (UserDataEraserInterface $eraser): string => (new \ReflectionClass($eraser))->getShortName(),
            $this->erasers(),
        ));
    }

    public function testEveryUserOwnedEntityIsErasedOrListedAsAGap(): void
    {
        $covered = [];
        foreach ($this->erasers() as $eraser) {
            array_push($covered, ...$eraser::covers());
        }

        $notErased = array_values(array_diff(
            array_keys(UserOwnedTables::in(static::getContainer()->get(EntityManagerInterface::class))),
            $covered,
        ));
        sort($notErased);
        $known = self::NOT_ERASED_YET;
        sort($known);

        self::assertSame(
            $known,
            $notErased,
            'A user-owned entity is not erased with its account. Name it in an eraser\'s covers(); '
            .'when a gap is closed, remove its entry from NOT_ERASED_YET.',
        );
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
