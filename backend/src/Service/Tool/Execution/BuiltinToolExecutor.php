<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\NativeToolRegistry;
use App\AI\Messages\Tools\WebFetchBudget;
use App\AI\Messages\Tools\WebSearchTool;
use App\Entity\User;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;

/**
 * Synaplan's built-in gateway tools: web search, page fetch, and image
 * analysis. File work stays on {@see \App\AI\Messages\Tools\CodeExecutionTool}
 * because that path already runs through the file-work runner.
 */
final readonly class BuiltinToolExecutor implements ToolExecutorInterface
{
    public function __construct(
        private ?NativeToolRegistry $native = null,
        private ?AnalyzeImageTool $image = null,
        private ?WebSearchTool $webSearch = null,
    ) {
    }

    public function supports(ToolDescriptor $tool): bool
    {
        return ToolSource::Builtin === $tool->source;
    }

    public function execute(ToolDescriptor $tool, ToolInvocation $invocation): ToolExecution
    {
        $user = $invocation->user;
        if (!$user instanceof User) {
            return $this->unknown($tool->name);
        }
        if (ToolChannel::OPENAI === $invocation->caller->channel) {
            return WebSearchTool::NAME === $tool->name
                ? $this->webSearch($invocation, $user)
                : $this->unknown($tool->name);
        }

        $native = $this->native?->tryExecute(
            $tool->name,
            $invocation->arguments,
            $user,
            $invocation->pages ?? new WebFetchBudget(0),
            $invocation->assistant,
        );
        if (null !== $native) {
            return $this->fromNative($native);
        }
        if (AnalyzeImageTool::NAME === $tool->name && null !== $this->image) {
            $result = $this->image->execute($invocation->arguments, $user->getId(), $user->getLocale());

            return new ToolExecution($result['text'], $result['isError'], 'builtin', 'read', details: [
                'usageSource' => 'VISION',
                'usageInput' => $result['summary'],
            ]);
        }

        return $this->unknown($tool->name);
    }

    private function webSearch(ToolInvocation $invocation, User $user): ToolExecution
    {
        if (null !== $this->native) {
            $native = $this->native->tryExecute(
                WebSearchTool::NAME,
                $invocation->arguments,
                $user,
                $invocation->pages ?? new WebFetchBudget(0),
                $invocation->assistant,
            );
            if (null !== $native) {
                return $this->fromNative($native);
            }
        }
        if (null === $this->webSearch) {
            return $this->unknown(WebSearchTool::NAME);
        }
        $result = $this->webSearch->execute($invocation->arguments);

        return new ToolExecution($result['text'], $result['isError'], 'builtin', 'read', details: [
            'usageSource' => 'WEB_SEARCH',
            'usageInput' => $result['query'],
        ]);
    }

    /**
     * @param array{text: string, isError: bool, summary: string, source: string} $native
     */
    private function fromNative(array $native): ToolExecution
    {
        return new ToolExecution($native['text'], $native['isError'], 'builtin', 'read', details: [
            'usageSource' => $native['source'],
            'usageInput' => $native['summary'],
        ]);
    }

    private function unknown(string $name): ToolExecution
    {
        return new ToolExecution(sprintf('Unknown Synaplan tool `%s`.', $name), true, 'builtin', 'read');
    }
}
