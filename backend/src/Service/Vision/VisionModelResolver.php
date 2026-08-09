<?php

declare(strict_types=1);

namespace App\Service\Vision;

use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;

/**
 * Resolves the vision-capable model to use when a turn carries images but the
 * active chat model cannot see them — shared by ChatHandler and the Messages
 * gateway so both honour the same PIC2TEXT → catalog fallback order.
 */
final readonly class VisionModelResolver
{
    public function __construct(
        private ModelConfigService $modelConfigService,
        private ModelRepository $modelRepository,
    ) {
    }

    /**
     * Order of preference:
     *   1. The account's configured image-recognition model
     *      (DEFAULTMODEL.PIC2TEXT), when it exists and is vision-capable.
     *   2. The global catalog fallback (highest-quality selectable vision chat
     *      model) — used only when the account has no usable configured model.
     */
    public function resolve(?int $userId): ?Model
    {
        $configuredId = $this->modelConfigService->getDefaultModel('PIC2TEXT', $userId);
        if ($configuredId) {
            $configured = $this->modelRepository->find($configuredId);
            if ($configured instanceof Model && $configured->hasFeature('vision')) {
                return $configured;
            }
        }

        return $this->modelRepository->findByFeature('vision', 'chat', true);
    }

    /**
     * True when Synaplan has at least one vision-capable model the account
     * (or the install-wide catalog) can use.
     */
    public function isAvailable(?int $userId): bool
    {
        return null !== $this->resolve($userId);
    }
}
