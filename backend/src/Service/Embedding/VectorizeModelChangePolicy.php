<?php

declare(strict_types=1);

namespace App\Service\Embedding;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Embedding\Exception\PremiumRequiredException;

/**
 * The one default-model change with consequences beyond the person saving it.
 *
 * VECTORIZE is read from the global row (ownerId=0) by every embedding call,
 * and a switch costs embedding credit on every later search, so it is a paid
 * feature even at the per-user scope (same rule as
 * {@see \App\Controller\AdminEmbeddingController}). This policy decides
 * whether a save really changes the model, gates that change, and refreshes
 * the cached binding afterwards.
 */
final readonly class VectorizeModelChangePolicy
{
    public function __construct(
        private ConfigRepository $configRepository,
        private EmbeddingModelChangeGuard $changeGuard,
        private EmbeddingMetadataService $embeddingMetadata,
    ) {
    }

    /**
     * Whether the requested defaults switch the embedding model.
     *
     * The AI Models page sends the unchanged VECTORIZE id it read next to the
     * capability the person edited (#891). That echo equals the global row and
     * must not count as a change, or a chat-only save would be refused.
     *
     * @param array<mixed, mixed> $requested capability => model id
     */
    public function isChange(array $requested): bool
    {
        return isset($requested['VECTORIZE'])
            && (int) $requested['VECTORIZE'] !== $this->currentModelId();
    }

    /**
     * @throws PremiumRequiredException when the plan does not include switching the embedding model
     */
    public function assertCanChange(User $user): void
    {
        $this->changeGuard->assertCanChange($user);
    }

    /**
     * Drop the cached binding so the next read (search, embedding status)
     * sees the new model at once.
     */
    public function changed(): void
    {
        $this->embeddingMetadata->invalidate();
    }

    /**
     * 0 when no global row exists yet, so any non-zero request is a change.
     */
    private function currentModelId(): int
    {
        $config = $this->configRepository->findOneBy([
            'ownerId' => 0,
            'group' => 'DEFAULTMODEL',
            'setting' => 'VECTORIZE',
        ]);

        return $config ? (int) $config->getValue() : 0;
    }
}
