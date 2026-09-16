<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class DocumentToolRegistry
{
    /** @var list<DocumentToolInterface> */
    private array $tools;

    /**
     * @param iterable<DocumentToolInterface> $tools
     */
    public function __construct(
        #[AutowireIterator('app.document_tool')]
        iterable $tools,
    ) {
        $this->tools = iterator_to_array($tools, false);
    }

    /**
     * @return list<DocumentToolInterface>
     */
    public function forKind(string $kind): array
    {
        return array_values(array_filter(
            $this->tools,
            static fn (DocumentToolInterface $tool): bool => in_array($kind, $tool->appliesTo(), true),
        ));
    }

    public function get(string $name): ?DocumentToolInterface
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @return list<array{type: 'function', function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function declarationsFor(string $kind): array
    {
        return array_map(
            static fn (DocumentToolInterface $tool): array => $tool->declaration(),
            $this->forKind($kind),
        );
    }
}
