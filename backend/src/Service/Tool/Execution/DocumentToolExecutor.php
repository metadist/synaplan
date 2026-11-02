<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Service\Document\Tool\DocumentToolRegistry;
use App\Service\Document\Tool\DocumentToolResult;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use Psr\Log\LoggerInterface;

/**
 * Runs one document tool against the caller's open document.
 */
final readonly class DocumentToolExecutor implements ToolExecutorInterface
{
    public function __construct(
        private DocumentToolRegistry $registry,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(ToolDescriptor $tool): bool
    {
        return ToolSource::Document === $tool->source;
    }

    public function execute(ToolDescriptor $tool, ToolInvocation $invocation): ToolExecution
    {
        $session = $invocation->caller->document;
        if (null === $session) {
            return new ToolExecution('There is no open document.', true, 'document', 'write');
        }
        $documentTool = $this->registry->get($invocation->toolName);
        if (null === $documentTool || !in_array($session->kind(), $documentTool->appliesTo(), true)) {
            return new ToolExecution('Unknown tool '.$invocation->toolName, true, 'document', 'write', DocumentToolResult::error(
                'Unknown tool '.$invocation->toolName,
                'processing.documentStepUnknownTool',
                ['name' => $invocation->toolName],
            ));
        }
        try {
            $result = $documentTool->execute($session, $invocation->arguments);
        } catch (\Throwable $e) {
            $this->logger->warning('DocumentToolExecutor: tool threw', [
                'tool' => $invocation->toolName,
                'error' => $e->getMessage(),
            ]);
            $result = DocumentToolResult::error($e->getMessage(), 'processing.documentStepFailed', ['name' => $invocation->toolName]);
        }
        $session->record($result);

        return new ToolExecution(
            $result->message,
            !$result->ok,
            'document',
            $result->mutates ? 'write' : 'read',
            $result,
        );
    }
}
