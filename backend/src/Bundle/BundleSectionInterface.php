<?php

declare(strict_types=1);

namespace App\Bundle;

interface BundleSectionInterface
{
    public function kind(): string;

    public function version(): int;

    public function isAvailable(?int $userId, BundleScope $scope): bool;

    /**
     * @return list<string>
     */
    public function dependsOn(): array;

    /**
     * @param array<string, mixed> $include
     *
     * @return list<array<string, mixed>>
     */
    public function export(int $userId, BundleScope $scope, array $include = []): array;

    /**
     * @param list<array<string, mixed>> $items
     */
    public function preview(array $items, int $userId): SectionPreview;

    /**
     * @param list<array<string, mixed>> $items
     */
    public function apply(array $items, int $userId, ImportOptions $options): SectionResult;
}
