<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Entity\CustomTool;
use App\Repository\CustomToolRepository;
use App\Service\Tool\Custom\HttpToolExecutor;
use App\Service\Tool\Custom\InvalidToolTemplateException;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use Psr\Log\LoggerInterface;

/**
 * One custom HTTP tool. Gateway, Saved Tasks and approval resume each
 * keep the sentence they already showed.
 */
final readonly class CustomHttpToolExecutor implements ToolExecutorInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ?CustomToolRepository $tools = null,
        private ?HttpToolExecutor $http = null,
        private ?ToolsConfig $config = null,
    ) {
    }

    public function supports(ToolDescriptor $tool): bool
    {
        return ToolSource::Custom === $tool->source;
    }

    public function execute(ToolDescriptor $tool, ToolInvocation $invocation): ToolExecution
    {
        $userId = (int) ($invocation->caller->userId ?? 0);
        $toolId = $tool->meta['toolId'] ?? null;
        $row = null !== $this->tools && is_numeric($toolId) ? $this->tools->find((int) $toolId) : null;

        if (ToolChannel::MESSAGES === $invocation->caller->channel) {
            return $this->gateway($invocation, $userId, $row);
        }
        if (null === $row) {
            $text = ToolChannel::RESUME === $invocation->caller->channel
                ? sprintf('Custom tool %s is no longer available', $invocation->toolName)
                : sprintf('Tool "%s" is not registered', $invocation->toolName);

            return new ToolExecution($text, true, 'custom', 'write', details: ['missing' => true]);
        }
        if (null === $this->http) {
            return new ToolExecution('This custom tool cannot run right now.', true, 'custom', 'write');
        }

        try {
            $result = $this->http->execute($row, $invocation->arguments, $userId);
        } catch (InvalidToolTemplateException $e) {
            $this->logger->info('ToolCallRunner: custom tool failed', [
                'tool' => $invocation->toolName,
                'error' => $e->getMessage(),
            ]);

            return new ToolExecution($e->getMessage(), true, 'custom', 'write');
        }

        return new ToolExecution((string) $result['summary'], false, 'custom', 'write', details: [
            'fields' => $result['fields'],
            'summary' => $result['summary'],
            'status' => $result['status'],
            'ref' => 'custom:'.$row->getId().':'.$result['status'],
        ]);
    }

    private function gateway(ToolInvocation $invocation, int $userId, ?CustomTool $row): ToolExecution
    {
        if (null === $this->http || null === $this->tools) {
            return new ToolExecution('This custom tool cannot run right now.', true, 'custom', 'write');
        }
        if (null !== $this->config && (!$this->config->isRegistryEnabled($userId) || !$this->config->isCustomHttpEnabled($userId))) {
            return new ToolExecution('This custom tool is not available.', true, 'custom', 'write');
        }
        if (!$row instanceof CustomTool || !$row->isEnabled()) {
            return new ToolExecution('This custom tool is not available.', true, 'custom', 'write');
        }

        try {
            $result = $this->http->execute($row, $invocation->arguments, $userId);
        } catch (InvalidToolTemplateException $e) {
            $this->logger->info('GatewayToolLoop: custom tool failed', [
                'tool' => $invocation->toolName,
                'error' => $e->getMessage(),
            ]);

            return new ToolExecution($e->getMessage(), true, 'custom', 'write');
        }

        $text = '' !== $result['summary'] ? $result['summary'] : 'The tool finished';
        $failed = $result['status'] < 200 || $result['status'] >= 300;

        return new ToolExecution($text, $failed, 'custom', 'write', details: [
            'fields' => $result['fields'],
            'summary' => $result['summary'],
            'status' => $result['status'],
        ]);
    }
}
