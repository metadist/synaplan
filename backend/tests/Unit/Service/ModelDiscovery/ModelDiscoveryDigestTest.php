<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ModelDiscovery;

use App\Service\ModelDiscovery\ModelDiscoveryDigest;
use App\Service\ModelDiscovery\ModelDiscoveryReport;
use PHPUnit\Framework\TestCase;

final class ModelDiscoveryDigestTest extends TestCase
{
    public function testGroupsVariantsUnderShorterRoot(): void
    {
        $lines = ModelDiscoveryDigest::formatReleaseGroups([
            [
                'provider' => 'openai',
                'id' => 'gpt-6-sol-mini',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
                'label' => 'New family',
            ],
            [
                'provider' => 'openai',
                'id' => 'gpt-6-sol',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
                'label' => 'New family',
            ],
            [
                'provider' => 'openai',
                'id' => 'gpt-6-sol-2026-09-14',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
                'label' => 'New family',
            ],
        ]);

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('gpt-6-sol (+2 variants:', $lines[0]);
        $this->assertStringContainsString('gpt-6-sol-2026-09-14', $lines[0]);
        $this->assertStringContainsString('gpt-6-sol-mini', $lines[0]);
    }

    public function testSortsByLabelFamilyThenGenerationThenLineThenVersion(): void
    {
        $lines = ModelDiscoveryDigest::formatReleaseGroups([
            [
                'provider' => 'openai',
                'id' => 'z-version',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
                'label' => 'New version of Z',
            ],
            [
                'provider' => 'openai',
                'id' => 'a-family',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
                'label' => 'New family',
            ],
            [
                'provider' => 'anthropic',
                'id' => 'b-generation',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
                'label' => 'New generation X of Y',
            ],
            [
                'provider' => 'openai',
                'id' => 'c-line',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
                'label' => 'New line in Foo',
            ],
        ]);

        $this->assertStringStartsWith('New family', $lines[0]);
        $this->assertStringStartsWith('New generation', $lines[1]);
        $this->assertStringStartsWith('New line', $lines[2]);
        $this->assertStringStartsWith('New version', $lines[3]);
    }

    public function testStillOpenSummaryOnNonMonday(): void
    {
        $digest = ModelDiscoveryDigest::fromReport(new ModelDiscoveryReport(
            providers: [],
            pending: [],
            newPending: [[
                'provider' => 'openai',
                'id' => 'fresh',
                'firstSeen' => '2026-09-24',
                'daysPending' => 0,
                'label' => 'New family',
            ]],
            openPending: [[
                'provider' => 'openai',
                'id' => 'old-one',
                'firstSeen' => '2026-09-10',
                'daysPending' => 14,
                'label' => 'New family',
            ]],
            failedProviders: [],
            baselinesRecorded: [],
            obsoleteIgnores: [],
            silencedByClass: [],
            shouldNotify: true,
            isMondayReminder: false,
        ));

        $this->assertSame('🆕 New AI models detected', $digest->title);
        $this->assertCount(1, $digest->newLines);
        $this->assertSame(
            ['1 more open, oldest since 2026-09-10 (14 days)'],
            $digest->stillOpenLines,
        );
        $this->assertTrue($digest->actionRequired);
    }

    public function testMondayReminderListsOpenGrouped(): void
    {
        $digest = ModelDiscoveryDigest::fromReport(new ModelDiscoveryReport(
            providers: [],
            pending: [],
            newPending: [],
            openPending: [
                [
                    'provider' => 'openai',
                    'id' => 'gpt-6-sol',
                    'firstSeen' => '2026-09-10',
                    'daysPending' => 14,
                    'label' => 'New family',
                ],
                [
                    'provider' => 'openai',
                    'id' => 'gpt-6-sol-mini',
                    'firstSeen' => '2026-09-10',
                    'daysPending' => 14,
                    'label' => 'New family',
                ],
            ],
            failedProviders: [[
                'provider' => 'groq',
                'detail' => 'timeout',
                'failingSince' => '2026-09-15',
                'isNew' => false,
            ]],
            baselinesRecorded: [],
            obsoleteIgnores: [],
            silencedByClass: [],
            shouldNotify: true,
            isMondayReminder: true,
        ));

        $this->assertSame('🔁 Weekly reminder: models still open', $digest->title);
        $this->assertSame([], $digest->newLines);
        $this->assertCount(1, $digest->stillOpenLines);
        $this->assertStringContainsString('gpt-6-sol (+1 variant', $digest->stillOpenLines[0]);
        $this->assertStringContainsString('since 2026-09-15', $digest->failedLines[0]);
    }

    public function testNonMondayOmitsOngoingFailures(): void
    {
        $digest = ModelDiscoveryDigest::fromReport(new ModelDiscoveryReport(
            providers: [],
            pending: [],
            newPending: [[
                'provider' => 'openai',
                'id' => 'fresh',
                'firstSeen' => '2026-09-24',
                'daysPending' => 0,
                'label' => 'New family',
            ]],
            openPending: [],
            failedProviders: [[
                'provider' => 'groq',
                'detail' => 'timeout',
                'failingSince' => '2026-09-15',
                'isNew' => false,
            ]],
            baselinesRecorded: [],
            obsoleteIgnores: [],
            silencedByClass: [],
            shouldNotify: true,
            isMondayReminder: false,
        ));

        $this->assertSame([], $digest->failedLines);
    }
}
