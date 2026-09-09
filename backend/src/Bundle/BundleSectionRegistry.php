<?php

declare(strict_types=1);

namespace App\Bundle;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class BundleSectionRegistry
{
    /** @var array<string, BundleSectionInterface> */
    private array $byKind = [];

    /**
     * @param iterable<BundleSectionInterface> $sections
     */
    public function __construct(
        #[AutowireIterator('app.bundle.section')]
        iterable $sections,
    ) {
        foreach ($sections as $section) {
            $kind = $section->kind();
            if (isset($this->byKind[$kind])) {
                throw new \LogicException(sprintf('Duplicate bundle section kind "%s"', $kind));
            }
            $this->byKind[$kind] = $section;
        }
    }

    /**
     * @return list<BundleSectionInterface>
     */
    public function available(?int $userId, BundleScope $scope): array
    {
        $sections = [];
        foreach ($this->byKind as $section) {
            if ($section->isAvailable($userId, $scope)) {
                $sections[] = $section;
            }
        }

        return $this->order($sections);
    }

    public function get(string $kind): ?BundleSectionInterface
    {
        return $this->byKind[$kind] ?? null;
    }

    /**
     * @return list<string>
     */
    public function registeredKinds(): array
    {
        return array_keys($this->byKind);
    }

    /**
     * @param list<BundleSectionInterface> $sections
     *
     * @return list<BundleSectionInterface>
     */
    private function order(array $sections): array
    {
        $byKind = [];
        foreach ($sections as $section) {
            $byKind[$section->kind()] = $section;
        }
        $ordered = [];
        $visiting = [];
        $visit = function (BundleSectionInterface $section) use (&$visit, &$ordered, &$visiting, $byKind): void {
            $kind = $section->kind();
            if (isset($ordered[$kind])) {
                return;
            }
            if (isset($visiting[$kind])) {
                throw new \LogicException(sprintf('Circular bundle section dependency at "%s"', $kind));
            }
            $visiting[$kind] = true;
            foreach ($section->dependsOn() as $dep) {
                if (isset($byKind[$dep])) {
                    $visit($byKind[$dep]);
                }
            }
            unset($visiting[$kind]);
            $ordered[$kind] = $section;
        };
        foreach ($sections as $section) {
            $visit($section);
        }

        return array_values($ordered);
    }
}
