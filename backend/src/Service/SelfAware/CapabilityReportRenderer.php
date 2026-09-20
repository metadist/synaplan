<?php

declare(strict_types=1);

namespace App\Service\SelfAware;

/**
 * Compact, deterministic prompt block for a {@see CapabilityReport}.
 *
 * Budget: ≤ ~500 tokens (~2 000 characters at 4 chars/token). The budget grew
 * with the product: assistants, approvals, custom tools and sharing joined the
 * inventory in 4.8/4.9, and an answer that omits them lies by omission. The
 * block stays cached per user and is only injected on the product topics.
 */
final readonly class CapabilityReportRenderer
{
    public const MAX_CHARS = 2000;

    public function render(CapabilityReport $report): string
    {
        $available = $this->joinFacts($report->byState(CapabilityState::Available), includeAlternative: false);
        $needsSetup = $this->joinNeedsSetup($report);
        $absent = $this->joinFacts($report->byState(CapabilityState::Absent), includeAlternative: true);

        $lines = [
            '## This Synaplan installation (live, version '.$report->version.')',
            $this->rulesLine($report),
            'AVAILABLE NOW: '.('' !== $available ? $available : 'none'),
            'NEEDS SETUP: '.('' !== $needsSetup ? $needsSetup : 'none'),
            'NOT AVAILABLE: '.('' !== $absent ? $absent : 'none'),
        ];

        $block = implode("\n", $lines);
        if (strlen($block) <= self::MAX_CHARS) {
            return $block;
        }

        $ellipsis = '…';

        return substr($block, 0, self::MAX_CHARS - strlen($ellipsis)).$ellipsis;
    }

    /**
     * @param list<CapabilityFact> $facts
     */
    private function joinFacts(array $facts, bool $includeAlternative): string
    {
        $parts = [];
        foreach ($facts as $fact) {
            $parts[] = $this->formatFact($fact, includeAlternative: $includeAlternative, includeAdminHint: false);
        }

        return implode(' · ', $parts);
    }

    private function joinNeedsSetup(CapabilityReport $report): string
    {
        $facts = $report->byState(CapabilityState::NeedsSetup);
        if ([] === $facts) {
            return '';
        }

        $parts = [];
        foreach ($facts as $fact) {
            $parts[] = $this->formatFact($fact, includeAlternative: false, includeAdminHint: $report->isAdmin);
        }
        $line = implode(' · ', $parts);
        if (!$report->isAdmin) {
            $line .= ' — ask your administrator';
        }

        return $line;
    }

    private function formatFact(CapabilityFact $fact, bool $includeAlternative, bool $includeAdminHint): string
    {
        $text = $fact->label;
        if ('' !== $fact->detail && CapabilityState::Available === $fact->state) {
            $text .= ' ('.$fact->detail.')';
        } elseif ('' !== $fact->detail && CapabilityState::NeedsSetup === $fact->state) {
            $text .= ' ('.$fact->detail.')';
        }
        if ($includeAlternative && null !== $fact->alternative && '' !== $fact->alternative) {
            $text .= ' — alternative: '.$fact->alternative;
        }
        if ($includeAdminHint && null !== $fact->adminHint && '' !== $fact->adminHint) {
            $text .= ' ('.$fact->adminHint.')';
        }

        return $text;
    }

    private function rulesLine(CapabilityReport $report): string
    {
        $rules = 'RULES: When asked whether you can do something, answer from the lists above and nothing else. '
            .'Say plainly what is not available here and offer the closest alternative. '
            .'Never promise, describe, or link a file you are not delivering in this turn. '
            .'Never quote prices, plan limits or quotas.';
        if ($report->billingEnabled) {
            $rules .= ' For plans and pricing, link the pricing page.';
        }

        return $rules;
    }
}
