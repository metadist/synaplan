<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

/**
 * Formats a {@see ModelDiscoveryReport} into Discord-ready fields.
 *
 * Label/grouping logic lives here — {@see DiscordNotificationService} only
 * builds the embed from the prepared lines.
 *
 * @phpstan-import-type PendingModel from ModelDiscoveryReport
 * @phpstan-import-type FailedProvider from ModelDiscoveryReport
 */
final readonly class ModelDiscoveryDigest
{
    /**
     * @param list<string> $newLines
     * @param list<string> $stillOpenLines
     * @param list<string> $failedLines
     * @param list<string> $baselineLines
     */
    public function __construct(
        public string $title,
        public array $newLines,
        public array $stillOpenLines,
        public array $failedLines,
        public array $baselineLines,
        public bool $actionRequired,
    ) {
    }

    public static function fromReport(ModelDiscoveryReport $report): self
    {
        $newLines = self::formatReleaseGroups($report->newPending);
        $stillOpenLines = self::stillOpenLines($report);
        $failedLines = self::failedLines($report);
        $baselineLines = self::baselineLines($report);
        $actionRequired = [] !== $newLines || [] !== $stillOpenLines;

        return new self(
            title: self::title($report, $newLines, $failedLines, $baselineLines),
            newLines: $newLines,
            stillOpenLines: $stillOpenLines,
            failedLines: $failedLines,
            baselineLines: $baselineLines,
            actionRequired: $actionRequired,
        );
    }

    /**
     * Group variants under a shorter root id (B starts with A + '-') within
     * one provider, then format one Discord line per release group.
     *
     * @param list<PendingModel> $items
     *
     * @return list<string>
     */
    public static function formatReleaseGroups(array $items): array
    {
        if ([] === $items) {
            return [];
        }

        $byProvider = [];
        foreach ($items as $item) {
            $byProvider[$item['provider']][] = $item;
        }

        $lines = [];
        foreach ($byProvider as $provider => $providerItems) {
            foreach (self::groupVariants($providerItems) as $group) {
                $root = $group['root'];
                $variants = $group['variants'];
                $label = $root['label'];
                $line = sprintf('%s — %s: %s', $label, $provider, $root['id']);
                if ([] !== $variants) {
                    $variantIds = array_map(static fn (array $v): string => $v['id'], $variants);
                    $line .= sprintf(
                        ' (+%d variant%s: %s)',
                        count($variantIds),
                        1 === count($variantIds) ? '' : 's',
                        implode(', ', $variantIds),
                    );
                }
                $lines[] = [
                    'line' => $line,
                    'sortKey' => self::labelSortKey($label),
                    'provider' => $provider,
                    'id' => $root['id'],
                ];
            }
        }

        usort(
            $lines,
            static fn (array $a, array $b): int => [$a['sortKey'], $a['provider'], $a['id']]
                <=> [$b['sortKey'], $b['provider'], $b['id']],
        );

        return array_map(static fn (array $row): string => $row['line'], $lines);
    }

    /**
     * @param list<PendingModel> $items
     *
     * @return list<array{root: PendingModel, variants: list<PendingModel>}>
     */
    public static function groupVariants(array $items): array
    {
        usort(
            $items,
            static fn (array $a, array $b): int => [strlen($a['id']), $a['id']] <=> [strlen($b['id']), $b['id']],
        );

        /** @var list<array{root: PendingModel, variants: list<PendingModel>}> $groups */
        $groups = [];
        foreach ($items as $item) {
            $placed = false;
            foreach ($groups as &$group) {
                $rootId = $group['root']['id'];
                if (str_starts_with($item['id'], $rootId.'-')) {
                    $group['variants'][] = $item;
                    $placed = true;
                    break;
                }
            }
            unset($group);

            if (!$placed) {
                $groups[] = ['root' => $item, 'variants' => []];
            }
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    private static function stillOpenLines(ModelDiscoveryReport $report): array
    {
        if ([] === $report->openPending) {
            return [];
        }

        if ($report->isMondayReminder) {
            return self::formatReleaseGroups($report->openPending);
        }

        $oldest = $report->openPending[0]['firstSeen'];
        $oldestDays = $report->openPending[0]['daysPending'];
        foreach ($report->openPending as $item) {
            if ($item['firstSeen'] < $oldest) {
                $oldest = $item['firstSeen'];
                $oldestDays = $item['daysPending'];
            }
        }

        return [sprintf(
            '%d more open, oldest since %s (%d day%s)',
            count($report->openPending),
            $oldest,
            $oldestDays,
            1 === $oldestDays ? '' : 's',
        )];
    }

    /**
     * @return list<string>
     */
    private static function failedLines(ModelDiscoveryReport $report): array
    {
        $lines = [];
        foreach ($report->failedProviders as $fail) {
            if (!$fail['isNew'] && !$report->isMondayReminder) {
                continue;
            }
            if ($report->isMondayReminder && !$fail['isNew']) {
                $lines[] = sprintf(
                    'could not check %s: %s (since %s)',
                    $fail['provider'],
                    $fail['detail'],
                    $fail['failingSince'],
                );
                continue;
            }
            $lines[] = sprintf('could not check %s: %s', $fail['provider'], $fail['detail']);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function baselineLines(ModelDiscoveryReport $report): array
    {
        if ([] === $report->baselinesRecorded) {
            return [];
        }

        $parts = [];
        $total = 0;
        foreach ($report->baselinesRecorded as $event) {
            $parts[] = $event['provider'];
            $total += $event['idCount'];
        }

        return [sprintf(
            'Baseline recorded for %s: %d ids; new models will be reported from tomorrow',
            implode(', ', $parts),
            $total,
        )];
    }

    /**
     * @param list<string> $newLines
     * @param list<string> $failedLines
     * @param list<string> $baselineLines
     */
    private static function title(
        ModelDiscoveryReport $report,
        array $newLines,
        array $failedLines,
        array $baselineLines,
    ): string {
        if ([] !== $newLines) {
            return '🆕 New AI models detected';
        }

        if ($report->isMondayReminder && ([] !== $report->openPending || self::hasOngoingFailures($report))) {
            return '🔁 Weekly reminder: models still open';
        }

        if ([] !== $failedLines) {
            return '⚠️ New-model check: some providers could not be checked';
        }

        if ([] !== $baselineLines) {
            return '✅ New-model check is active';
        }

        return '🆕 New AI models detected';
    }

    private static function hasOngoingFailures(ModelDiscoveryReport $report): bool
    {
        foreach ($report->failedProviders as $fail) {
            if (!$fail['isNew']) {
                return true;
            }
        }

        return false;
    }

    private static function labelSortKey(string $label): int
    {
        return match (true) {
            str_starts_with($label, 'New family') => 0,
            str_starts_with($label, 'New generation') => 1,
            str_starts_with($label, 'New line') => 2,
            str_starts_with($label, 'New version') => 3,
            default => 4, // New variant
        };
    }
}
