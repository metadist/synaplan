<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command;

enum ResultType: string
{
    case Info = 'info';
    case NeedsConfirmation = 'needs_confirmation';
    case Success = 'success';
    case Error = 'error';
}
