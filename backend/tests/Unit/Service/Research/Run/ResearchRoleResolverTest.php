<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\User;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchInputBudget;
use App\Service\Research\Run\ResearchModelChoice;
use App\Service\Research\Run\ResearchModelHints;
use App\Service\Research\Run\ResearchModelLookup;
use App\Service\Research\Run\ResearchRoleResolver;
use App\Service\Research\Run\ResearchWorld;
use App\Service\Research\Run\RoleUnavailable;
use PHPUnit\Framework\TestCase;

final class ResearchRoleResolverTest extends TestCase
{
    public function testChainsFallThroughWhenTheNewSlotsAreUnbound(): void
    {
        $roles = $this->resolver(new FakeLookup(
            slots: [
                'SORT' => $this->choice(2, 'groq', 'openai'),
                'ANALYZE' => $this->choice(4, 'google', 'google'),
                'CHAT' => $this->choice(9, 'anthropic', 'anthropic'),
            ],
        ))->resolve($this->user(), ResearchDepth::Standard, new ResearchWorld(false), new ResearchModelHints(), null);

        $this->assertSame(2, $roles->reader);
        $this->assertSame(4, $roles->checker);
        $this->assertSame(2, $roles->sorter);
        $this->assertFalse($roles->checkerSameFamily);
    }

    public function testARetiredBindingUsesItsSuccessor(): void
    {
        $roles = $this->resolver(new FakeLookup(
            slots: [
                'READ' => $this->choice(1, 'openai', 'openai', retired: true, successorId: 5),
                'SORT' => $this->choice(2, 'groq', 'openai'),
                'ANALYZE' => $this->choice(4, 'google', 'google'),
                'CHAT' => $this->choice(9, 'anthropic', 'anthropic'),
            ],
            byId: [5 => $this->choice(5, 'openai', 'openai')],
        ))->resolve($this->user(), ResearchDepth::Standard, new ResearchWorld(false), new ResearchModelHints(), null);

        $this->assertSame(5, $roles->reader);
    }

    public function testADisallowedModelIsSkippedForTheNextSlot(): void
    {
        $roles = $this->resolver(new FakeLookup(
            slots: [
                'READ' => $this->choice(1, 'openai', 'openai', allowed: false),
                'SORT' => $this->choice(2, 'groq', 'openai'),
                'ANALYZE' => $this->choice(4, 'google', 'google'),
                'CHAT' => $this->choice(9, 'anthropic', 'anthropic'),
            ],
        ))->resolve($this->user(), ResearchDepth::Standard, new ResearchWorld(false), new ResearchModelHints(), null);

        $this->assertSame(2, $roles->reader);
    }

    public function testAnUnhealthyModelIsSkipped(): void
    {
        $roles = $this->resolver(new FakeLookup(
            slots: [
                'READ' => $this->choice(1, 'openai', 'openai', healthy: false),
                'SORT' => $this->choice(2, 'groq', 'openai'),
                'ANALYZE' => $this->choice(4, 'google', 'google'),
                'CHAT' => $this->choice(9, 'anthropic', 'anthropic'),
            ],
        ))->resolve($this->user(), ResearchDepth::Standard, new ResearchWorld(false), new ResearchModelHints(), null);

        $this->assertSame(2, $roles->reader);
    }

    public function testADesktopWorldRefusesAProviderItDoesNotAllow(): void
    {
        $lookup = new FakeLookup(
            slots: [
                'READ' => $this->choice(1, 'groq', 'openai'),
                'SORT' => $this->choice(2, 'groq', 'openai'),
                'ANALYZE' => $this->choice(4, 'google', 'google'),
                'CHAT' => $this->choice(9, 'anthropic', 'anthropic'),
            ],
            byId: [3 => $this->choice(3, 'ollama', 'openai')],
        );

        $this->expectException(RoleUnavailable::class);
        $this->expectExceptionMessage('No model is available for reading sources.');

        try {
            $this->resolver($lookup)->resolve($this->user(), ResearchDepth::Standard, new ResearchWorld(true, ['ollama']), new ResearchModelHints(), 3);
        } catch (RoleUnavailable $e) {
            $this->assertSame('reader', $e->role);
            throw $e;
        }
    }

    public function testTheCheckerMovesToAnotherFamilyWhenACandidateExists(): void
    {
        $roles = $this->resolver(new FakeLookup(
            slots: [
                'SORT' => $this->choice(2, 'groq', 'openai'),
                'VERIFY' => $this->choice(8, 'anthropic', 'anthropic'),
                'CHAT' => $this->choice(9, 'anthropic', 'anthropic'),
            ],
            candidates: [$this->choice(4, 'google', 'google')],
        ))->resolve($this->user(), ResearchDepth::Standard, new ResearchWorld(false), new ResearchModelHints(), 9);

        $this->assertSame(4, $roles->checker);
        $this->assertFalse($roles->checkerSameFamily);
    }

    public function testTheCheckerStaysAndSaysSoWhenNoOtherFamilyExists(): void
    {
        $roles = $this->resolver(new FakeLookup(
            slots: [
                'SORT' => $this->choice(2, 'anthropic', 'anthropic'),
                'VERIFY' => $this->choice(8, 'anthropic', 'anthropic'),
                'CHAT' => $this->choice(9, 'anthropic', 'anthropic'),
            ],
        ))->resolve($this->user(), ResearchDepth::Standard, new ResearchWorld(false), new ResearchModelHints(), 9);

        $this->assertSame(8, $roles->checker);
        $this->assertTrue($roles->checkerSameFamily);
        $this->assertSame('same_family', $roles->disclosures[0]['code']);
    }

    public function testA33kModelReadsOnlyWhatItsWindowHolds(): void
    {
        $this->assertSame(18300, (new ResearchInputBudget())->tokens('reader', 33000));

        $roles = $this->resolver(new FakeLookup(
            slots: [
                'READ' => $this->choice(7, 'ollama', 'alibaba', contextWindow: 33000),
                'SORT' => $this->choice(2, 'ollama', 'openai'),
                'ANALYZE' => $this->choice(4, 'google', 'google'),
                'CHAT' => $this->choice(9, 'anthropic', 'anthropic'),
            ],
        ))->resolve($this->user(), ResearchDepth::Standard, new ResearchWorld(false), new ResearchModelHints(), 9);

        $this->assertSame(['code' => 'short_context', 'role' => 'reader', 'tokens' => 18300], $roles->disclosures[0]);
    }

    private function resolver(ResearchModelLookup $lookup): ResearchRoleResolver
    {
        return new ResearchRoleResolver($lookup);
    }

    private function user(): User
    {
        $user = new User();
        $id = new \ReflectionProperty(User::class, 'id');
        $id->setValue($user, 7);

        return $user;
    }

    private function choice(
        int $id,
        string $provider,
        string $family,
        bool $allowed = true,
        bool $healthy = true,
        bool $retired = false,
        ?int $successorId = null,
        ?int $contextWindow = 128000,
    ): ResearchModelChoice {
        return new ResearchModelChoice($id, $provider, $family, $allowed, $healthy, $retired, $successorId, $contextWindow);
    }
}

final class FakeLookup implements ResearchModelLookup
{
    /**
     * @param array<string, ResearchModelChoice|null> $slots
     * @param array<int, ResearchModelChoice>         $byId
     * @param array<string, ResearchModelChoice>      $byKey
     * @param list<ResearchModelChoice>               $candidates
     */
    public function __construct(
        private array $slots = [],
        private array $byId = [],
        private array $byKey = [],
        private array $candidates = [],
    ) {
    }

    public function slot(int $userId, string $slot): ?ResearchModelChoice
    {
        return $this->slots[$slot] ?? null;
    }

    public function byKey(int $userId, string $catalogKey): ?ResearchModelChoice
    {
        return $this->byKey[$catalogKey] ?? null;
    }

    public function byId(int $userId, int $id): ?ResearchModelChoice
    {
        return $this->byId[$id] ?? null;
    }

    public function verifyCandidates(int $userId): array
    {
        return $this->candidates;
    }
}
