<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\Entity\User;

/**
 * Built-in Messages-gateway tools the model can call and Synaplan runs.
 *
 * The gateway loop asks this registry instead of growing a branch per tool.
 * Web search stays here so the loop's constructor does not gain a parameter.
 */
final readonly class NativeToolRegistry
{
    public function __construct(
        private WebSearchTool $webSearch,
        private WebFetchTool $webFetch,
    ) {
    }

    public function webSearch(): WebSearchTool
    {
        return $this->webSearch;
    }

    public function webFetch(): WebFetchTool
    {
        return $this->webFetch;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param WebFetchBudget       $pages     pages left for the message this call belongs to
     *
     * @return array{text: string, isError: bool, summary: string, source: string}|null null when this registry does not own the name
     */
    public function tryExecute(string $name, array $arguments, User $user, WebFetchBudget $pages): ?array
    {
        if (WebSearchTool::NAME === $name) {
            $result = $this->webSearch->execute($arguments);

            return [
                'text' => $result['text'],
                'isError' => $result['isError'],
                'summary' => $result['query'],
                'source' => 'WEB_SEARCH',
            ];
        }

        if (WebFetchTool::NAME === $name) {
            $result = $this->webFetch->execute($arguments, $user, $pages);

            return [
                'text' => $result['text'],
                'isError' => $result['isError'],
                'summary' => $result['url'],
                'source' => 'WEB_FETCH',
            ];
        }

        return null;
    }
}
