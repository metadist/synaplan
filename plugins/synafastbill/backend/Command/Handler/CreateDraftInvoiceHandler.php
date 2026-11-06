<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command\Handler;

use Plugin\Synafastbill\Command\CommandHandlerInterface;
use Plugin\Synafastbill\Command\CommandResult;
use Plugin\Synafastbill\Command\HandlerContext;
use Plugin\Synafastbill\Dto\Customer;
use Plugin\Synafastbill\Dto\Invoice;
use Plugin\Synafastbill\Dto\InvoiceItem;
use Plugin\Synafastbill\Intent\CommandType;
use Plugin\Synafastbill\Intent\ParsedCommand;

/**
 * Guided draft-invoice creation:
 *   1. search the customer base for the hint, disambiguate if needed
 *   2. offer to copy the customer's most recent invoice as a starting point
 *   3. show the full draft and create it (as a DRAFT) only after confirmation
 *
 * State between turns lives in the session (steps: select_customer, confirm_create).
 */
final class CreateDraftInvoiceHandler implements CommandHandlerInterface
{
    private const STEP_SELECT_CUSTOMER = 'select_customer';
    private const STEP_CONFIRM_CREATE = 'confirm_create';

    public function owns(): CommandType
    {
        return CommandType::CreateDraftInvoice;
    }

    public function handle(ParsedCommand $command, HandlerContext $context): CommandResult
    {
        if (CommandType::Cancel === $command->type) {
            $context->forget();

            return CommandResult::info($context->t->trans('create.cancelled'));
        }

        if (CommandType::Confirm === $command->type) {
            return match ($context->step()) {
                self::STEP_SELECT_CUSTOMER => $this->resumeSelection($command, $context),
                self::STEP_CONFIRM_CREATE => $this->createDraft($context),
                default => CommandResult::info($context->t->trans('create.none_in_progress')),
            };
        }

        if (self::STEP_SELECT_CUSTOMER === $context->step()) {
            return CommandResult::confirm($context->t->trans('create.pick_number'));
        }

        return $this->startSearch($command, $context);
    }

    private function startSearch(ParsedCommand $command, HandlerContext $context): CommandResult
    {
        $hint = trim($command->stringParam('customer_hint'));
        if ('' === $hint) {
            return CommandResult::info($context->t->trans('create.which_customer'));
        }

        $candidates = $this->matchCustomers($context->client->getCustomers(['TERM' => $hint], 20), $hint);

        if ([] === $candidates) {
            return CommandResult::info($context->t->trans('create.not_found', $hint));
        }

        if (1 === count($candidates)) {
            return $this->proposeDraft($candidates[0], $context);
        }

        $stored = [];
        $lines = [$context->t->trans('create.found_many', count($candidates), $hint), ''];
        foreach ($candidates as $index => $customer) {
            $lines[] = sprintf('%d. %s%s', $index + 1, $customer->displayName(), $this->locationSuffix($customer));
            $stored[] = ['id' => $customer->id, 'name' => $customer->displayName()];
        }
        $lines[] = '';
        $lines[] = $context->t->trans('create.reply_number');

        $context->remember(CommandType::CreateDraftInvoice, self::STEP_SELECT_CUSTOMER, ['candidates' => $stored]);

        return CommandResult::confirm(implode("\n", $lines), ['candidates' => $stored]);
    }

    private function resumeSelection(ParsedCommand $command, HandlerContext $context): CommandResult
    {
        /** @var array<int, array{id: int, name: string}> $candidates */
        $candidates = $context->data()['candidates'] ?? [];
        $selection = (int) ($command->params['selection'] ?? 0);

        if ($selection < 1 || $selection > count($candidates)) {
            return CommandResult::confirm($context->t->trans('create.reply_between', count($candidates)));
        }

        $chosen = $candidates[$selection - 1];
        $customer = $this->reloadCustomer($context, (int) $chosen['id'], (string) $chosen['name']);

        return $this->proposeDraft($customer, $context);
    }

    private function proposeDraft(Customer $customer, HandlerContext $context): CommandResult
    {
        $previous = $this->latestInvoice($context, $customer->id);

        if (null === $previous || [] === $previous->items) {
            return CommandResult::info($context->t->trans(
                'create.no_previous',
                $customer->displayName(),
                $customer->id,
                '' !== $customer->city ? ', '.$customer->city : '',
            ));
        }

        $items = $previous->items;
        $context->remember(CommandType::CreateDraftInvoice, self::STEP_CONFIRM_CREATE, [
            'customer' => ['id' => $customer->id, 'name' => $customer->displayName()],
            'currency' => $previous->currency,
            'items' => array_map($this->itemToArray(...), $items),
        ]);

        return CommandResult::confirm($this->draftSummary($customer, $previous, $items, $context), [
            'customer_id' => $customer->id,
            'items' => count($items),
        ]);
    }

    private function createDraft(HandlerContext $context): CommandResult
    {
        $data = $context->data();
        /** @var array{id: int, name: string} $customer */
        $customer = $data['customer'] ?? ['id' => 0, 'name' => ''];
        $currency = is_string($data['currency'] ?? null) ? (string) $data['currency'] : $context->config->currency;
        $items = $this->itemsFromArray(is_array($data['items'] ?? null) ? $data['items'] : []);

        if (0 === (int) $customer['id'] || [] === $items) {
            $context->forget();

            return CommandResult::error($context->t->trans('create.incomplete'));
        }

        $invoiceId = $context->client->createDraftInvoice((int) $customer['id'], $items, $currency);
        $context->forget();

        return CommandResult::success(
            $context->t->trans('create.success', $invoiceId, (string) $customer['name'], count($items)),
            ['invoice_id' => $invoiceId],
        );
    }

    /**
     * @param array<int, Customer> $customers
     *
     * @return array<int, Customer>
     */
    private function matchCustomers(array $customers, string $hint): array
    {
        $needle = mb_strtolower(trim($hint));
        if ('' === $needle) {
            return [];
        }

        $matches = [];
        foreach ($customers as $customer) {
            foreach ($customer->searchableTerms() as $term) {
                if (str_contains(mb_strtolower($term), $needle)) {
                    $matches[$customer->id] = $customer;
                    break;
                }
            }
        }

        return array_values($matches);
    }

    private function reloadCustomer(HandlerContext $context, int $id, string $fallbackName): Customer
    {
        foreach ($context->client->getCustomers(['CUSTOMER_ID' => $id], 1) as $customer) {
            return $customer;
        }

        return new Customer($id, '', '', $fallbackName, '', '', '', '');
    }

    private function latestInvoice(HandlerContext $context, int $customerId): ?Invoice
    {
        $invoices = $context->client->getInvoices(['CUSTOMER_ID' => $customerId], 10);
        $latest = null;
        foreach ($invoices as $invoice) {
            if (null === $latest || $invoice->invoiceDate > $latest->invoiceDate) {
                $latest = $invoice;
            }
        }

        return $latest;
    }

    /**
     * @param array<int, InvoiceItem> $items
     */
    private function draftSummary(Customer $customer, Invoice $previous, array $items, HandlerContext $context): string
    {
        $lines = [
            $context->t->trans('create.found_customer', $customer->displayName(), $customer->id, '' !== $customer->city ? ', '.$customer->city : ''),
            $context->t->trans('create.similar_question', '' !== $previous->invoiceNumber ? $previous->invoiceNumber : ('#'.$previous->id), $previous->invoiceDate),
            '',
            sprintf('| %s | %s | %s | %s |', $context->t->trans('create.col.desc'), $context->t->trans('create.col.qty'), $context->t->trans('create.col.unitprice'), $context->t->trans('create.col.vat')),
            '|---|---:|---:|---:|',
        ];
        $net = 0.0;
        foreach ($items as $item) {
            $lines[] = sprintf('| %s | %s | %s | %s%% |', $item->description, $this->number($item->quantity), $this->money($item->unitPrice, $previous->currency), $this->number($item->vatPercent));
            $net += $item->netTotal();
        }
        $lines[] = '';
        $lines[] = $context->t->trans('create.net_total', $this->money($net, $previous->currency));

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string|float>
     */
    private function itemToArray(InvoiceItem $item): array
    {
        return [
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'unit_price' => $item->unitPrice,
            'vat_percent' => $item->vatPercent,
        ];
    }

    /**
     * @param array<int, mixed> $rows
     *
     * @return array<int, InvoiceItem>
     */
    private function itemsFromArray(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = new InvoiceItem(
                description: (string) ($row['description'] ?? ''),
                quantity: (float) ($row['quantity'] ?? 0),
                unit: (string) ($row['unit'] ?? ''),
                unitPrice: (float) ($row['unit_price'] ?? 0),
                vatPercent: (float) ($row['vat_percent'] ?? 0),
            );
        }

        return $items;
    }

    private function locationSuffix(Customer $customer): string
    {
        $parts = array_filter(['' !== $customer->customerNumber ? '#'.$customer->customerNumber : '', $customer->city]);

        return [] === $parts ? '' : ' ('.implode(', ', $parts).')';
    }

    private function money(float $amount, string $currency): string
    {
        return number_format($amount, 2, '.', ',').' '.$currency;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
