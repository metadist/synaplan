<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command\Handler;

use Plugin\Synafastbill\Command\CommandHandlerInterface;
use Plugin\Synafastbill\Command\CommandResult;
use Plugin\Synafastbill\Command\HandlerContext;
use Plugin\Synafastbill\Dto\Invoice;
use Plugin\Synafastbill\Intent\BankStatementParser;
use Plugin\Synafastbill\Intent\CommandType;
use Plugin\Synafastbill\Intent\ParsedCommand;

/**
 * Register a payment pasted from a bank statement against an open invoice.
 * Matches by invoice number first, then by exact amount; always confirms before
 * calling `invoice.setpaid`.
 */
final class RegisterPaymentHandler implements CommandHandlerInterface
{
    private const STEP_SELECT = 'select_payment';
    private const STEP_CONFIRM = 'confirm_payment';

    public function __construct(
        private readonly BankStatementParser $bankParser = new BankStatementParser(),
    ) {
    }

    public function owns(): CommandType
    {
        return CommandType::RegisterPayment;
    }

    public function handle(ParsedCommand $command, HandlerContext $context): CommandResult
    {
        if (CommandType::Cancel === $command->type) {
            $context->forget();

            return CommandResult::info($context->t->trans('pay.cancelled'));
        }

        if (CommandType::Confirm === $command->type) {
            return match ($context->step()) {
                self::STEP_SELECT => $this->resumeSelection($command, $context),
                self::STEP_CONFIRM => $command->consentsToWrite()
                    ? $this->registerPayment($context)
                    : CommandResult::confirm($context->t->trans('pay.say_yes')),
                default => CommandResult::info($context->t->trans('pay.none_in_progress')),
            };
        }

        if (self::STEP_SELECT === $context->step()) {
            return CommandResult::confirm($context->t->trans('pay.pick_number'));
        }

        return $this->startMatch($command, $context);
    }

    private function startMatch(ParsedCommand $command, HandlerContext $context): CommandResult
    {
        $bankText = $command->stringParam('bank_text', $command->rawText);
        $parsed = $this->bankParser->parse($bankText);

        if (null === $parsed['amount']) {
            return CommandResult::info($context->t->trans('pay.no_amount'));
        }
        if ($parsed['amount'] <= 0) {
            return CommandResult::info($context->t->trans('pay.not_incoming'));
        }

        $paidDate = $parsed['date'] ?? $context->clock->today()->format('Y-m-d');
        $open = $this->openInvoices($context);
        $candidates = $this->match($open, $parsed['amount'], $parsed['invoice_number'], $parsed['currency']);

        if ([] === $candidates) {
            $forInvoice = null !== $parsed['invoice_number'] ? $context->t->trans('pay.for_invoice', $parsed['invoice_number']) : '';

            return CommandResult::info($context->t->trans(
                'pay.no_match',
                $this->money($parsed['amount'], $parsed['currency'] ?? 'EUR'),
                $forInvoice,
            ));
        }

        if (1 === count($candidates)) {
            $invoice = $candidates[0];
            $context->remember(CommandType::RegisterPayment, self::STEP_CONFIRM, [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoiceNumber,
                'paid_date' => $paidDate,
                'amount' => $parsed['amount'],
            ]);

            return CommandResult::confirm($context->t->trans(
                'pay.confirm',
                $this->money($parsed['amount'], $invoice->currency),
                $paidDate,
                '' !== $invoice->invoiceNumber ? $invoice->invoiceNumber : ('#'.$invoice->id),
                $this->money($invoice->total, $invoice->currency),
            ), ['invoice_id' => $invoice->id]);
        }

        $stored = [];
        $lines = [$context->t->trans('pay.several', $this->money($parsed['amount'], $parsed['currency'] ?? 'EUR')), ''];
        foreach ($candidates as $index => $invoice) {
            $lines[] = sprintf('%d. %s — %s (due %s)', $index + 1, '' !== $invoice->invoiceNumber ? $invoice->invoiceNumber : ('#'.$invoice->id), $this->money($invoice->total, $invoice->currency), $invoice->dueDate);
            $stored[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoiceNumber,
                'currency' => $invoice->currency,
            ];
        }
        $lines[] = '';
        $lines[] = $context->t->trans('pay.reply_number');

        $context->remember(CommandType::RegisterPayment, self::STEP_SELECT, [
            'candidates' => $stored,
            'paid_date' => $paidDate,
            'amount' => $parsed['amount'],
        ]);

        return CommandResult::confirm(implode("\n", $lines), ['candidates' => $stored]);
    }

    private function resumeSelection(ParsedCommand $command, HandlerContext $context): CommandResult
    {
        /** @var array<int, array{invoice_id: int, invoice_number: string, currency?: string}> $candidates */
        $candidates = $context->data()['candidates'] ?? [];
        $selection = (int) ($command->params['selection'] ?? 0);

        if ($selection < 1 || $selection > count($candidates)) {
            return CommandResult::confirm($context->t->trans('pay.reply_between', count($candidates)));
        }

        $chosen = $candidates[$selection - 1];
        $context->remember(CommandType::RegisterPayment, self::STEP_CONFIRM, [
            'invoice_id' => (int) $chosen['invoice_id'],
            'invoice_number' => (string) $chosen['invoice_number'],
            'paid_date' => (string) ($context->data()['paid_date'] ?? $context->clock->today()->format('Y-m-d')),
            'amount' => (float) ($context->data()['amount'] ?? 0),
        ]);

        $currency = is_string($chosen['currency'] ?? null) && '' !== $chosen['currency']
            ? (string) $chosen['currency']
            : 'EUR';

        return CommandResult::confirm($context->t->trans(
            'pay.confirm_short',
            $this->money((float) ($context->data()['amount'] ?? 0), $currency),
            (string) ($context->data()['paid_date'] ?? ''),
            '' !== $chosen['invoice_number'] ? (string) $chosen['invoice_number'] : ('#'.$chosen['invoice_id']),
        ));
    }

    private function registerPayment(HandlerContext $context): CommandResult
    {
        $data = $context->data();
        $invoiceId = (int) ($data['invoice_id'] ?? 0);
        $paidDate = (string) ($data['paid_date'] ?? $context->clock->today()->format('Y-m-d'));
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;

        if (0 === $invoiceId) {
            $context->forget();

            return CommandResult::error($context->t->trans('pay.incomplete'));
        }

        $context->client->setInvoicePaid($invoiceId, $paidDate, $amount);
        $context->forget();

        $label = is_string($data['invoice_number'] ?? null) && '' !== $data['invoice_number']
            ? (string) $data['invoice_number']
            : '#'.$invoiceId;

        return CommandResult::success(
            $context->t->trans('pay.success', $label, $paidDate),
            ['invoice_id' => $invoiceId],
        );
    }

    /**
     * @return array<int, Invoice>
     */
    private function openInvoices(HandlerContext $context): array
    {
        return array_values(array_filter(
            $context->client->getAllInvoices(['TYPE' => 'outgoing']),
            static fn (Invoice $invoice): bool => !$invoice->isPaid() && !$invoice->canceled && !$invoice->isDraft(),
        ));
    }

    /**
     * @param array<int, Invoice> $open
     *
     * @return array<int, Invoice>
     */
    private function match(array $open, float $amount, ?string $invoiceNumber, ?string $currency): array
    {
        if (null !== $currency && '' !== $currency) {
            $open = array_values(array_filter(
                $open,
                static fn (Invoice $invoice): bool => 0 === strcasecmp($invoice->currency, $currency),
            ));
        }

        if (null !== $invoiceNumber && '' !== $invoiceNumber) {
            $byNumber = array_values(array_filter(
                $open,
                static fn (Invoice $invoice): bool => '' !== $invoice->invoiceNumber
                    && (str_contains($invoice->invoiceNumber, $invoiceNumber) || str_contains($invoiceNumber, $invoice->invoiceNumber)),
            ));
            if ([] !== $byNumber) {
                return $byNumber;
            }
        }

        return array_values(array_filter(
            $open,
            static fn (Invoice $invoice): bool => abs($invoice->total - $amount) < 0.01,
        ));
    }

    private function money(float $amount, string $currency): string
    {
        return number_format($amount, 2, '.', ',').' '.$currency;
    }
}
