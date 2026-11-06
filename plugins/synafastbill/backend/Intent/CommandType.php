<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Intent;

/**
 * The structured commands the plugin understands. This is the contract between
 * the (fuzzy) natural-language input and the (deterministic) handlers.
 */
enum CommandType: string
{
    case CreateDraftInvoice = 'invoice.draft.create';
    case RegisterPayment = 'payment.register';
    case ShowOverdue = 'invoice.list.overdue';
    case Confirm = 'session.confirm';
    case Cancel = 'session.cancel';
    case Help = 'help';
    case Unknown = 'unknown';
}
