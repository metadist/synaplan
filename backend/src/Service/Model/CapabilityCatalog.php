<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\AI\Credential\ChatReadinessService;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\Iam\Policy\GroupPolicyService;

/**
 * Capability-grouped selectable models for machine clients (Synaplan Desktop).
 *
 * Tag → group mapping matches {@see \App\Controller\ConfigController::getModels()}
 * but only the eight project-companion groups. {@see id} is the catalog key
 * `service:providerId:tag`, not the database BID.
 */
final readonly class CapabilityCatalog
{
    /**
     * Exact DEFAULTMODEL names the desktop Models panel maps to slots.
     *
     * @var list<string>
     */
    public const GROUPS = [
        'CHAT',
        'SOUND2TEXT',
        'TEXT2SOUND',
        'PIC2TEXT',
        'TEXT2PIC',
        'TEXT2VID',
        'VECTORIZE',
        'ANALYZE',
    ];

    public function __construct(
        private ModelRepository $modelRepository,
        private ChatReadinessService $chatReadiness,
        private ?GroupPolicyService $groupPolicyService = null,
    ) {
    }

    /**
     * @return array{object: 'catalog', capabilities: array<string, list<array<string, mixed>>>}
     */
    public function forUser(User $user): array
    {
        $availability = $this->chatReadiness->providerAvailability();
        $models = $this->modelRepository->findBy(
            ['active' => 1],
            ['quality' => 'DESC', 'rating' => 'DESC']
        );

        $grouped = [];
        foreach (self::GROUPS as $group) {
            $grouped[$group] = [];
        }

        foreach ($models as $model) {
            if (1 !== $model->getSelectable()) {
                continue;
            }
            if ($model->isHiddenBecauseFree()) {
                continue;
            }
            if ('rerank' === strtolower($model->getTag())) {
                continue;
            }

            $tag = strtoupper($model->getTag());
            $groups = self::groupsForTag($tag, $model->getFeatures());
            if ([] === $groups) {
                continue;
            }

            ['available' => $available, 'reason' => $unavailableReason] = $this->chatReadiness->modelAvailability(
                $model->getService(),
                $model->getProviderId(),
                $availability,
            );

            $row = [
                'id' => $model->getId(),
                'service' => $model->getService(),
                'providerId' => $model->getProviderId(),
                'tag' => $tag,
                'name' => $model->getName(),
                'available' => $available,
                'unavailableReason' => $unavailableReason,
            ];

            foreach ($groups as $group) {
                $grouped[$group][] = $row;
            }
        }

        if (null !== $this->groupPolicyService) {
            foreach ($grouped as $capability => $rows) {
                $grouped[$capability] = $this->groupPolicyService->filterModelsByAllowList($user->getId(), $rows);
            }
        }

        $capabilities = [];
        foreach (self::GROUPS as $group) {
            $capabilities[$group] = array_map(
                static fn (array $row): array => self::toEntry($row),
                $grouped[$group],
            );
        }

        return [
            'object' => 'catalog',
            'capabilities' => $capabilities,
        ];
    }

    /**
     * Same tag rules as the web config-models picker, limited to desktop groups.
     *
     * @param list<mixed> $features
     *
     * @return list<string>
     */
    public static function groupsForTag(string $tag, array $features = []): array
    {
        $tag = strtoupper($tag);

        return match ($tag) {
            'CHAT' => ['CHAT', 'ANALYZE'],
            'ANALYZE' => ['ANALYZE'],
            'VECTORIZE', 'EMBEDDING' => ['VECTORIZE'],
            'VISION', 'PIC2TEXT' => ['PIC2TEXT'],
            'IMAGE', 'TEXT2PIC' => ['TEXT2PIC'],
            'VIDEO', 'TEXT2VID' => self::isImageToVideo($features) ? [] : ['TEXT2VID'],
            'AUDIO', 'SOUND2TEXT', 'TRANSCRIPTION' => ['SOUND2TEXT'],
            'TTS', 'TEXT2SOUND' => ['TEXT2SOUND'],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: string, providerId: string, service: string, name: string, available: bool, unavailableReason: string|null}
     */
    private static function toEntry(array $row): array
    {
        $service = (string) ($row['service'] ?? '');
        $providerId = (string) ($row['providerId'] ?? '');
        $tag = (string) ($row['tag'] ?? '');

        return [
            'id' => GroupPolicyService::catalogKey($service, $providerId, $tag),
            'providerId' => $providerId,
            'service' => strtolower($service),
            'name' => (string) ($row['name'] ?? ''),
            'available' => (bool) ($row['available'] ?? false),
            'unavailableReason' => isset($row['unavailableReason']) && is_string($row['unavailableReason'])
                ? $row['unavailableReason']
                : null,
        ];
    }

    /**
     * @param list<mixed> $features
     */
    private static function isImageToVideo(array $features): bool
    {
        return in_array('image2video', $features, true);
    }
}
