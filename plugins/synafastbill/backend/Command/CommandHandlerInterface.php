<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command;

use Plugin\Synafastbill\Intent\CommandType;
use Plugin\Synafastbill\Intent\ParsedCommand;

interface CommandHandlerInterface
{
    /**
     * The top-level command this handler owns (used both to route fresh
     * commands and to resume a pending session created by this handler).
     */
    public function owns(): CommandType;

    public function handle(ParsedCommand $command, HandlerContext $context): CommandResult;
}
