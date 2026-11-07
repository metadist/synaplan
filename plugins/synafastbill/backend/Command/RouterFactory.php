<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command;

use Plugin\Synafastbill\Api\FastBillClient;
use Plugin\Synafastbill\Command\Handler\CreateDraftInvoiceHandler;
use Plugin\Synafastbill\Command\Handler\HelpHandler;
use Plugin\Synafastbill\Command\Handler\OverdueInvoicesHandler;
use Plugin\Synafastbill\Command\Handler\RegisterPaymentHandler;
use Plugin\Synafastbill\Command\Session\SessionStoreInterface;
use Plugin\Synafastbill\Intent\AiCommandExtractorInterface;
use Plugin\Synafastbill\Intent\IntentParser;
use Plugin\Synafastbill\Localization\Translator;

/**
 * Wires the full command pipeline together. Framework-free so the controller,
 * the offline example, and the tests all build the router the same way.
 */
final class RouterFactory
{
    public static function create(
        FastBillClient $client,
        SessionStoreInterface $sessions,
        PluginConfig $config,
        ?ClockInterface $clock = null,
        ?AiCommandExtractorInterface $aiExtractor = null,
    ): CommandRouter {
        $clock ??= new SystemClock();

        return new CommandRouter(
            new IntentParser($aiExtractor),
            $sessions,
            $client,
            $clock,
            $config,
            new Translator($config->language()),
            [
                new CreateDraftInvoiceHandler(),
                new RegisterPaymentHandler(),
                new OverdueInvoicesHandler(),
                new HelpHandler(),
            ],
        );
    }
}
