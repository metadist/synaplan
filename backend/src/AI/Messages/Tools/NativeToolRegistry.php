<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\Entity\User;
use App\Service\Runtime\RuntimeProfile;

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
        private ?KnowledgeSearchTool $knowledgeSearch = null,
        private ?ReadDocumentTool $readDocument = null,
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

    public function knowledgeSearch(): ?KnowledgeSearchTool
    {
        return $this->knowledgeSearch;
    }

    public function readDocument(): ?ReadDocumentTool
    {
        return $this->readDocument;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param WebFetchBudget       $pages     pages left for the message this call belongs to
     *
     * @return array{text: string, isError: bool, summary: string, source: string}|null null when this registry does not own the name
     */
    public function tryExecute(string $name, array $arguments, User $user, WebFetchBudget $pages, ?RuntimeProfile $assistant = null): ?array
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

        if (null !== $this->knowledgeSearch && KnowledgeSearchTool::NAME === $name) {
            $result = $this->knowledgeSearch->execute($arguments, $user, $assistant);

            return [
                'text' => $result['text'],
                'isError' => $result['isError'],
                'summary' => $result['summary'],
                'source' => 'KNOWLEDGE_SEARCH',
            ];
        }

        if (null !== $this->readDocument && ReadDocumentTool::NAME === $name) {
            $result = $this->readDocument->execute($arguments, $user, $assistant);

            return [
                'text' => $result['text'],
                'isError' => $result['isError'],
                'summary' => $result['summary'],
                'source' => 'READ_DOCUMENT',
            ];
        }

        return null;
    }
}
