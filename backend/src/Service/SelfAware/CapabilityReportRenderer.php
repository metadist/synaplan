<?php

declare(strict_types=1);

namespace App\Service\SelfAware;

/**
 * Compact, deterministic prompt block for a {@see CapabilityReport}.
 *
 * Budget: ≤ ~600 tokens (~2 400 characters at 4 chars/token). The budget grew
 * with the product: assistants, approvals, custom tools and sharing joined the
 * inventory in 4.8/4.9, and an answer that omits them lies by omission. The
 * block stays cached per user and is only injected on the product topics.
 * RULES and the optional MODEL line ride directly under the header so a
 * truncated tail can never cut them. MODEL is per request (the model that
 * is answering), so it is passed in rather than stored on the report.
 */
final readonly class CapabilityReportRenderer
{
    public const MAX_CHARS = 2400;

    /**
     * @param list<string> $connectedSources display names of the user's enabled MCP connections
     */
    public function render(CapabilityReport $report, ?string $modelLine = null, array $connectedSources = []): string
    {
        $available = $this->joinFacts($report->byState(CapabilityState::Available), includeAlternative: false);
        $needsSetup = $this->joinNeedsSetup($report);
        $absent = $this->joinFacts($report->byState(CapabilityState::Absent), includeAlternative: true);
        $sources = $this->sourceLine($connectedSources);

        $lines = [
            '## This Synaplan installation (live, version '.$report->version.')',
            $this->rulesLine($report, null !== $sources),
        ];
        if (null !== $modelLine && '' !== trim($modelLine)) {
            $lines[] = $modelLine;
        }
        if (null !== $sources) {
            $lines[] = $sources;
        }
        $lines[] = 'AVAILABLE NOW: '.('' !== $available ? $available : 'none');
        $lines[] = 'NEEDS SETUP: '.('' !== $needsSetup ? $needsSetup : 'none');
        $lines[] = 'NOT AVAILABLE: '.('' !== $absent ? $absent : 'none');

        $block = implode("\n", $lines);
        if (strlen($block) <= self::MAX_CHARS) {
            return $block;
        }

        $ellipsis = '…';

        // Byte budget, character boundary: mb_strcut backs up to a valid UTF-8
        // sequence so a multibyte label can never be split mid-character.
        return mb_strcut($block, 0, self::MAX_CHARS - strlen($ellipsis), 'UTF-8').$ellipsis;
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

    private function rulesLine(CapabilityReport $report, bool $hasConnectedSources): string
    {
        $rules = 'RULES: Answer capability questions from the lists below only. '
            .'Say plainly what is not available here and offer the closest alternative. '
            .'Never promise, describe, or link a file you are not delivering in this turn. '
            .'Never quote prices, plan limits or quotas.';
        if ($report->billingEnabled) {
            $rules .= ' For plans and pricing, link the pricing page.';
        }
        if ($hasConnectedSources) {
            $rules .= ' If no results from a connected source are in this turn, say the lookup did not run this time and ask the user to try again. Never tell the user to connect a source that is listed as connected.';
        }

        return $rules;
    }

    /**
     * JSON-quoted names, capped so a long connection list cannot eat the budget.
     * Names are untrusted configuration text.
     *
     * @param list<string> $connectedSources
     */
    private function sourceLine(array $connectedSources): ?string
    {
        $quoted = [];
        foreach ($connectedSources as $name) {
            if ('' === trim($name)) {
                continue;
            }
            $quoted[] = json_encode($name, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            if (count($quoted) >= 8) {
                break;
            }
        }
        if ([] === $quoted) {
            return null;
        }

        $line = 'CONNECTED SOURCES: '.implode(', ', $quoted);
        if (count($connectedSources) > count($quoted)) {
            $line .= ', …';
        }

        return $line;
    }
}
