<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Seed\RateLimitConfigSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Guards the rate-limit seeding contract for MAX_OUTPUT_TOKENS.
 *
 * Authenticated tiers (NEW/PRO/TEAM/BUSINESS) must NOT seed a per-plan output
 * cap — they receive the model's full max_tokens, bounded by the cost-budget
 * gate / message-count limits instead. Only ANONYMOUS keeps a hard cap. Without
 * this guard a future refactor could silently reintroduce per-plan caps and
 * quietly truncate AI responses again.
 */
final class RateLimitConfigSeederTest extends TestCase
{
    /**
     * @return list<array{ownerId: int, group: string, setting: string, value: string}>
     */
    private function defaults(): array
    {
        $reflection = new \ReflectionClass(RateLimitConfigSeeder::class);
        $defaults = $reflection->getReflectionConstant('DEFAULTS');
        $this->assertNotFalse($defaults, 'DEFAULTS constant missing');

        /** @var list<array{ownerId: int, group: string, setting: string, value: string}> $rows */
        $rows = $defaults->getValue();
        $this->assertNotEmpty($rows, 'Expected at least one DEFAULTS row');

        return $rows;
    }

    public function testMaxOutputTokensIsSeededOnlyForAnonymous(): void
    {
        $groupsWithCap = [];
        foreach ($this->defaults() as $row) {
            if ('MAX_OUTPUT_TOKENS' === $row['setting']) {
                $groupsWithCap[] = $row['group'];
            }
        }

        $this->assertSame(
            ['RATELIMITS_ANONYMOUS'],
            $groupsWithCap,
            'MAX_OUTPUT_TOKENS must be seeded for ANONYMOUS only; authenticated tiers '
            .'(NEW/PRO/TEAM/BUSINESS) must use the model full max_tokens.',
        );
    }

    public function testAnonymousMaxOutputTokensCapIsPositive(): void
    {
        foreach ($this->defaults() as $row) {
            if ('RATELIMITS_ANONYMOUS' === $row['group'] && 'MAX_OUTPUT_TOKENS' === $row['setting']) {
                $this->assertGreaterThan(
                    0,
                    (int) $row['value'],
                    'ANONYMOUS MAX_OUTPUT_TOKENS must be a positive cap.',
                );

                return;
            }
        }

        $this->fail('Expected a RATELIMITS_ANONYMOUS MAX_OUTPUT_TOKENS default row.');
    }
}
