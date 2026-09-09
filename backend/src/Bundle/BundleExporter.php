<?php

declare(strict_types=1);

namespace App\Bundle;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class BundleExporter
{
    public function __construct(
        private BundleSectionRegistry $registry,
        private BundleEnvelopeValidator $validator,
        #[Autowire('%kernel.secret%')]
        private string $appSecret,
    ) {
    }

    public function instanceFingerprint(): string
    {
        return 'sha256:'.hash('sha256', $this->appSecret.':synaplan-bundle-instance');
    }

    /**
     * @param list<string>         $kinds
     * @param array<string, mixed> $include
     *
     * @return array<string, mixed>
     */
    public function export(int $userId, BundleScope $scope, array $kinds, array $include = []): array
    {
        $available = [];
        foreach ($this->registry->available($userId, $scope) as $section) {
            $available[$section->kind()] = $section;
        }
        if ([] === $kinds) {
            $kinds = array_keys($available);
        }

        $sections = [];
        foreach ($this->registry->available($userId, $scope) as $section) {
            if (!in_array($section->kind(), $kinds, true)) {
                continue;
            }
            $items = $section->export($userId, $scope, $include);
            $sections[] = [
                'kind' => $section->kind(),
                'version' => $section->version(),
                'items' => $items,
            ];
        }

        $document = [
            'schema' => BundleEnvelopeValidator::SCHEMA,
            'createdAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'sourceInstance' => $this->instanceFingerprint(),
            'sourceVersion' => 'dev',
            'scope' => $scope->value,
            'sections' => $sections,
        ];

        $this->validator->parse(json_encode($document, JSON_THROW_ON_ERROR), $this->registry->registeredKinds());

        return $document;
    }
}
