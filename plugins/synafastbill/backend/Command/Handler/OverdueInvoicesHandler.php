<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command\Handler;

use Plugin\Synafastbill\Command\CommandHandlerInterface;
use Plugin\Synafastbill\Command\CommandResult;
use Plugin\Synafastbill\Command\HandlerContext;
use Plugin\Synafastbill\Dto\Invoice;
use Plugin\Synafastbill\Intent\CommandType;
use Plugin\Synafastbill\Intent\ParsedCommand;

/**
 * Read-only: list overdue (open, past-due, non-draft, non-cancelled) invoices.
 * No confirmation needed — it never writes.
 */
final class OverdueInvoicesHandler implements CommandHandlerInterface
{
    public function owns(): CommandType
    {
        return CommandType::ShowOverdue;
    }

    public function handle(ParsedCommand $command, HandlerContext $context): CommandResult
    {
        $today = $context->clock->today();
        $invoices = $context->client->getAllInvoices(['TYPE' => 'outgoing']);

        $overdue = array_values(array_filter(
            $invoices,
            static fn (Invoice $invoice): bool => $invoice->isOverdue($today),
        ));

        if ([] === $overdue) {
            return CommandResult::success($context->t->trans('overdue.none'));
        }

        usort($overdue, static fn (Invoice $a, Invoice $b): int => $b->daysOverdue($today) <=> $a->daysOverdue($today));

        $lines = [
            $context->t->trans('overdue.title'),
            '',
            sprintf('| %s | %s | %s | %s |', $context->t->trans('overdue.col.invoice'), $context->t->trans('overdue.col.due'), $context->t->trans('overdue.col.dayslate'), $context->t->trans('overdue.col.amount')),
            '|---|---|---:|---:|',
        ];
        $total = 0.0;
        foreach ($overdue as $invoice) {
            $lines[] = sprintf(
                '| %s | %s | %d | %s |',
                '' !== $invoice->invoiceNumber ? $invoice->invoiceNumber : ('#'.$invoice->id),
                $invoice->dueDate,
                $invoice->daysOverdue($today),
                $this->money($invoice->total, $invoice->currency),
            );
            $total += $invoice->total;
        }
        $currency = $overdue[0]->currency;
        $lines[] = '';
        $lines[] = $context->t->trans('overdue.summary', count($overdue), $this->money($total, $currency));

        return CommandResult::info(implode("\n", $lines), ['count' => count($overdue), 'total' => round($total, 2)]);
    }

    private function money(float $amount, string $currency): string
    {
        return number_format($amount, 2, '.', ',').' '.$currency;
    }
}
