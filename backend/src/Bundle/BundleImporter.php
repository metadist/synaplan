<?php

declare(strict_types=1);

namespace App\Bundle;

use Doctrine\ORM\EntityManagerInterface;

final readonly class BundleImporter
{
    public function __construct(
        private BundleSectionRegistry $registry,
        private BundleEnvelopeValidator $validator,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{envelope: array<string, mixed>, sections: list<array<string, mixed>>, fromOtherInstance: bool, sourceInstance: string}
     */
    public function preview(string $json, int $userId, string $localInstance): array
    {
        $envelope = $this->validator->parse($json, $this->registry->registeredKinds());
        $scope = BundleScope::from($envelope['scope']);
        $previews = [];
        foreach ($this->orderedSections($envelope['sections'], $userId, $scope) as [$section, $items]) {
            $previews[] = $section->preview($items, $userId)->toArray();
        }

        return [
            'envelope' => [
                'schema' => $envelope['schema'],
                'createdAt' => $envelope['createdAt'],
                'sourceVersion' => $envelope['sourceVersion'],
                'scope' => $envelope['scope'],
            ],
            'fromOtherInstance' => $envelope['sourceInstance'] !== $localInstance,
            'sourceInstance' => $envelope['sourceInstance'],
            'sections' => $previews,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function apply(string $json, int $userId, ImportOptions $options): array
    {
        $envelope = $this->validator->parse($json, $this->registry->registeredKinds());
        $scope = BundleScope::from($envelope['scope']);
        $results = [];
        foreach ($this->orderedSections($envelope['sections'], $userId, $scope) as [$section, $items]) {
            $this->em->beginTransaction();
            try {
                $result = $section->apply($items, $userId, $options);
                $this->em->flush();
                $this->em->commit();
                $results[] = $result->toArray();
            } catch (\Throwable $e) {
                $this->em->rollback();
                $results[] = (new SectionResult(
                    $section->kind(),
                    failed: [['key' => $section->kind(), 'reason' => $e->getMessage()]],
                ))->toArray();
            }
        }

        return $results;
    }

    /**
     * @param list<array{kind: string, version: int, items: list<array<string, mixed>>}> $sections
     *
     * @return list<array{0: BundleSectionInterface, 1: list<array<string, mixed>>}>
     */
    private function orderedSections(array $sections, int $userId, BundleScope $scope): array
    {
        $byKind = [];
        foreach ($sections as $section) {
            $byKind[$section['kind']] = $section['items'];
        }
        $pairs = [];
        foreach ($this->registry->available($userId, $scope) as $handler) {
            if (!isset($byKind[$handler->kind()])) {
                continue;
            }
            $pairs[] = [$handler, $byKind[$handler->kind()]];
        }

        return $pairs;
    }
}
