<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command;

use Plugin\Synafastbill\Api\Exception\FastBillApiException;
use Plugin\Synafastbill\Api\Exception\FastBillException;
use Plugin\Synafastbill\Api\FastBillClient;
use Plugin\Synafastbill\Command\Session\SessionStoreInterface;
use Plugin\Synafastbill\Intent\CommandType;
use Plugin\Synafastbill\Intent\IntentParser;
use Plugin\Synafastbill\Localization\Translator;

/**
 * The heart of the plugin: for one chat turn it parses the prompt, resolves it
 * against any pending confirmation session, dispatches to the owning handler,
 * and persists the resulting session state. Framework-free and fully testable.
 */
final class CommandRouter
{
    /** @var array<string, CommandHandlerInterface> keyed by CommandType value */
    private array $handlers = [];

    /**
     * @param iterable<CommandHandlerInterface> $handlers
     */
    public function __construct(
        private readonly IntentParser $parser,
        private readonly SessionStoreInterface $sessions,
        private readonly FastBillClient $client,
        private readonly ClockInterface $clock,
        private readonly PluginConfig $config,
        private readonly Translator $translator,
        iterable $handlers = [],
    ) {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->owns()->value] = $handler;
        }
    }

    public function dispatch(string $userText, string $sessionKey): CommandResult
    {
        $command = $this->parser->parse($userText);
        $session = $this->sessions->load($sessionKey);
        $sessionOwner = is_string($session['handler'] ?? null) ? (string) $session['handler'] : null;

        $isSessionAnswer = in_array($command->type, [CommandType::Confirm, CommandType::Cancel], true);

        if ($isSessionAnswer) {
            if (null === $session || null === $sessionOwner || !isset($this->handlers[$sessionOwner])) {
                return CommandResult::info($this->nothingToConfirmMessage());
            }
            $target = $this->handlers[$sessionOwner];
            $context = $this->contextFromSession($session);

            return $this->run($target, $command, $context, $sessionKey);
        }

        if (isset($this->handlers[$command->type->value])) {
            // A fresh top-level command abandons any in-flight session.
            if (null !== $session) {
                $this->sessions->clear($sessionKey);
            }
            $target = $this->handlers[$command->type->value];
            $context = new HandlerContext($this->client, $this->clock, $this->config, $this->translator);

            return $this->run($target, $command, $context, $sessionKey);
        }

        // Unknown input while a flow is active → treat as a continuation answer.
        if (null !== $session && null !== $sessionOwner && isset($this->handlers[$sessionOwner])) {
            $target = $this->handlers[$sessionOwner];
            $context = $this->contextFromSession($session);

            return $this->run($target, $command, $context, $sessionKey);
        }

        // Nothing matched → help.
        if (isset($this->handlers[CommandType::Help->value])) {
            $context = new HandlerContext($this->client, $this->clock, $this->config, $this->translator);

            return $this->run($this->handlers[CommandType::Help->value], $command, $context, $sessionKey);
        }

        return CommandResult::info($this->nothingToConfirmMessage());
    }

    private function run(CommandHandlerInterface $handler, \Plugin\Synafastbill\Intent\ParsedCommand $command, HandlerContext $context, string $sessionKey): CommandResult
    {
        try {
            $result = $handler->handle($command, $context);
        } catch (FastBillApiException $e) {
            $this->sessions->clear($sessionKey);

            return CommandResult::error($this->apiErrorMessage($e));
        } catch (FastBillException $e) {
            $this->sessions->clear($sessionKey);

            return CommandResult::error($e->getMessage());
        }

        if ($context->shouldForget()) {
            $this->sessions->clear($sessionKey);
        } elseif (null !== $context->pendingState()) {
            $this->sessions->save($sessionKey, $context->pendingState());
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function contextFromSession(array $session): HandlerContext
    {
        $step = is_string($session['step'] ?? null) ? (string) $session['step'] : null;
        /** @var array<string, mixed> $data */
        $data = is_array($session['data'] ?? null) ? $session['data'] : [];

        return new HandlerContext($this->client, $this->clock, $this->config, $this->translator, $step, $data);
    }

    private function apiErrorMessage(FastBillApiException $e): string
    {
        if ($e->isRateLimited()) {
            return $this->translator->trans('error.rate_limited');
        }
        if ($e->isAuthenticationError()) {
            return $this->translator->trans('error.auth');
        }

        return $this->translator->trans('error.generic', implode('; ', $e->errors));
    }

    private function nothingToConfirmMessage(): string
    {
        return $this->translator->trans('router.nothing');
    }
}
