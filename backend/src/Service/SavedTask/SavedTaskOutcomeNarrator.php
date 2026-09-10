<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

/**
 * Turns a failed Saved Task run into one honest sentence: what finished,
 * and what did not. Avoids "Nothing was sent or saved" after a later step fails.
 */
final class SavedTaskOutcomeNarrator
{
    /**
     * @param array<string, mixed>       $result
     * @param list<array<string, mixed>> $cards
     */
    public function failureMessage(array $result, array $cards, ?string $processorError = null): string
    {
        $done = [];
        $failed = [];
        foreach ($this->statuses($result, $cards) as $capability => $status) {
            $label = $this->label($capability);
            if ('done' === $status || 'stopped' === $status) {
                $done[$label] = true;
            } elseif ('failed' === $status) {
                $failed[$label] = true;
            }
        }
        $doneLabels = array_keys($done);
        $failedLabels = array_keys($failed);
        if ([] !== $doneLabels && [] !== $failedLabels) {
            return sprintf(
                '%s finished. %s did not complete.',
                $this->join($doneLabels),
                $this->join($failedLabels),
            );
        }
        if ([] !== $doneLabels) {
            return $this->join($doneLabels).' finished, but the run could not be confirmed as complete.';
        }
        if (is_string($processorError) && '' !== trim($processorError)
            && !str_contains($processorError, 'Nothing was sent or saved')) {
            return $processorError;
        }

        return 'This run stopped before anything was sent or saved.';
    }

    /**
     * @param array<string, mixed>       $result
     * @param list<array<string, mixed>> $cards
     *
     * @return array<string, string> capability => status
     */
    private function statuses(array $result, array $cards): array
    {
        $out = [];
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $multitask = is_array($metadata['multitask'] ?? null) ? $metadata['multitask'] : [];
        $byNode = is_array($multitask['node_statuses'] ?? null) ? $multitask['node_statuses'] : [];
        foreach ($cards as $card) {
            $capability = is_string($card['capability'] ?? null) ? $card['capability'] : 'step';
            $nodeId = is_string($card['nodeId'] ?? $card['node_id'] ?? null) ? (string) ($card['nodeId'] ?? $card['node_id']) : '';
            $status = is_string($card['state'] ?? $card['status'] ?? null) ? (string) ($card['state'] ?? $card['status']) : '';
            if ('' !== $nodeId && is_string($byNode[$nodeId] ?? null)) {
                $status = $byNode[$nodeId];
            }
            if ('' !== $status) {
                $out[$capability] = $status;
            }
        }
        if ([] === $out && [] !== $byNode) {
            foreach ($byNode as $status) {
                if (is_string($status)) {
                    $out['step'] = $status;
                }
            }
        }

        return $out;
    }

    private function label(string $capability): string
    {
        return match ($capability) {
            'email_me' => 'The email',
            'save_to_folder' => 'Saving the file',
            'outbound_webhook' => 'Sending to the other system',
            'tool_call', 'mcp_action' => 'The connected action',
            'email_search' => 'The mailbox search',
            'chat', 'summarize' => 'The written answer',
            'calendar_event' => 'The calendar entry',
            default => 'A step',
        };
    }

    /**
     * @param list<string> $parts
     */
    private function join(array $parts): string
    {
        if (1 === count($parts)) {
            return $parts[0];
        }
        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }
}
