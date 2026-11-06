<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command\Handler;

use Plugin\Synafastbill\Command\CommandHandlerInterface;
use Plugin\Synafastbill\Command\CommandResult;
use Plugin\Synafastbill\Command\HandlerContext;
use Plugin\Synafastbill\Intent\CommandType;
use Plugin\Synafastbill\Intent\ParsedCommand;

/**
 * Static command overview. Also serves as the fallback for input the parser
 * could not classify (CommandType::Unknown), with a short "didn't get that"
 * preface.
 */
final class HelpHandler implements CommandHandlerInterface
{
    public function owns(): CommandType
    {
        return CommandType::Help;
    }

    public function handle(ParsedCommand $command, HandlerContext $context): CommandResult
    {
        $overview = $context->t->trans('help.overview');

        if (CommandType::Unknown === $command->type) {
            return CommandResult::info($context->t->trans('help.unknown_prefix')."\n\n".$overview);
        }

        return CommandResult::info($overview);
    }
}
