<?php

declare(strict_types=1);

namespace App\Bundle;

use App\Bundle\Section\SavedTasksBundleSection;
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
        $fileTopics = $this->agentTopics($envelope['sections']);
        $previews = [];
        foreach ($this->orderedSections($envelope['sections'], $userId, $scope) as [$section, $items]) {
            $preview = $section instanceof SavedTasksBundleSection
                ? $section->preview($items, $userId, $fileTopics)
                : $section->preview($items, $userId);
            $previews[] = $preview->toArray();
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
        $fileTopics = $this->agentTopics($envelope['sections']);
        $results = [];
        foreach ($this->orderedSections($envelope['sections'], $userId, $scope) as [$section, $items]) {
            $this->em->beginTransaction();
            try {
                $result = $section instanceof SavedTasksBundleSection
                    ? $section->apply($items, $userId, $options, $fileTopics)
                    : $section->apply($items, $userId, $options);
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

    /**
     * @param list<array{kind: string, version: int, items: list<array<string, mixed>>}> $sections
     *
     * @return list<string>
     */
    private function agentTopics(array $sections): array
    {
        $topics = [];
        foreach ($sections as $section) {
            if ('agents' !== ($section['kind'] ?? null)) {
                continue;
            }
            foreach ($section['items'] as $item) {
                $key = is_string($item['key'] ?? null) ? $item['key'] : '';
                if ('' !== $key) {
                    $topics[] = 'agent:'.$key;
                }
            }
        }

        return $topics;
    }
}
