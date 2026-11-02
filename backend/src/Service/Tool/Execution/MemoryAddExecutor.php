<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Entity\User;
use App\Service\Exception\MemoryServiceUnavailableException;
use App\Service\Memory\UserMemoryService;
use App\Service\RateLimitService;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;

/**
 * `memory_add` on Synaplan's own MCP server. The gate stays off, which
 * is the gap AU25 closes.
 */
final readonly class MemoryAddExecutor implements ToolExecutorInterface
{
    public function __construct(
        private UserMemoryService $memories,
        private RateLimitService $rates,
    ) {
    }

    public function supports(ToolDescriptor $tool): bool
    {
        return ToolSource::Plugin === $tool->source && 'memory_add' === $tool->name;
    }

    public function execute(ToolDescriptor $tool, ToolInvocation $invocation): ToolExecution
    {
        unset($tool);
        $user = $invocation->user;
        if (!$user instanceof User) {
            return new ToolExecution('Memory service is currently unavailable.', true, 'plugin', 'write');
        }
        if (!($this->rates->checkLimit($user, 'EMBEDDINGS')['allowed'] ?? false)) {
            return new ToolExecution('Rate limit exceeded for embeddings.', true, 'plugin', 'write');
        }
        if (!$this->memories->isAvailable()) {
            return new ToolExecution('Memory service is currently unavailable.', true, 'plugin', 'write');
        }

        $category = is_string($invocation->arguments['category'] ?? null) ? $invocation->arguments['category'] : '';
        $key = is_string($invocation->arguments['key'] ?? null) ? $invocation->arguments['key'] : '';
        $value = is_string($invocation->arguments['value'] ?? null) ? $invocation->arguments['value'] : '';

        try {
            $memory = $this->memories->createMemory($user, $category, $key, $value, 'user_created');
        } catch (\InvalidArgumentException $e) {
            return new ToolExecution($e->getMessage(), true, 'plugin', 'write');
        } catch (MemoryServiceUnavailableException) {
            return new ToolExecution('Memory service is currently unavailable.', true, 'plugin', 'write');
        }

        return new ToolExecution('Memory saved.', false, 'plugin', 'write', details: [
            'payload' => [
                'success' => true,
                'memory' => $memory->toArray(),
            ],
        ]);
    }
}
