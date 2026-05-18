<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\Embedding\EmbeddingModelChangeGuard;
use App\Service\Embedding\Exception\PremiumRequiredException;
use App\Service\Model\Exception\InvalidPromptModelException;

/**
 * Validates that a user is allowed to pin a specific model as the
 * `aiModel` metadata on a task prompt.
 *
 * Background — issue #891
 * -----------------------
 * `/api/v1/config/models/defaults` blocks non-premium users from
 * switching the VECTORIZE model via {@see EmbeddingModelChangeGuard}.
 * Before this validator the `PromptController` (and the legacy API)
 * accepted any model id into the prompt's `aiModel` metadata field,
 * letting a free user assign a VECTORIZE-tagged model to a prompt and
 * therefore bypass the config-page premium gate.
 *
 * This service is the single point of truth for "may this user pin
 * model X as a prompt's aiModel?". It deliberately reuses the existing
 * embedding guard rather than inventing a new "premium model" flag —
 * the codebase has no per-model premium attribute today (see the
 * orgaralf comment on issue #891). When such an attribute is later
 * introduced, the extra check belongs here and nowhere else.
 *
 * The validator is intentionally minimal:
 *   - `null`, `0`, `-1` and any value <= 0 mean "no override" and are
 *     a no-op (the existing UI uses `-1` as the "use default" sentinel,
 *     see `PromptService::loadMetadataForPrompt`).
 *   - Unknown / inactive models are rejected with
 *     {@see InvalidPromptModelException} (HTTP 400 at the controller
 *     boundary) — saving a stale id is a frontend bug and never useful.
 *   - VECTORIZE/EMBEDDING-tagged models go through
 *     {@see EmbeddingModelChangeGuard::assertCanChange()} so the same
 *     subscription tier rule applies on both surfaces. Premium and
 *     admin users keep working as before.
 */
final readonly class PromptModelEligibilityValidator
{
    /** Tags that identify embedding-only models — kept in sync with ConfigController::getModels grouping. */
    private const EMBEDDING_TAGS = ['VECTORIZE', 'EMBEDDING'];

    public function __construct(
        private ModelRepository $modelRepository,
        private EmbeddingModelChangeGuard $embeddingChangeGuard,
    ) {
    }

    /**
     * Validate every model-bearing field in the given prompt metadata.
     *
     * Currently only the `aiModel` key is gated; new fields can be
     * added here in the future without touching the call sites.
     *
     * @param array<string, mixed> $metadata raw metadata payload as provided by the client
     *
     * @throws InvalidPromptModelException when a referenced model id is unknown or inactive
     * @throws PremiumRequiredException    when the user's plan does not allow the chosen model
     */
    public function assertMetadataAllowed(User $user, array $metadata): void
    {
        if (!array_key_exists('aiModel', $metadata)) {
            return;
        }

        $rawModelId = $metadata['aiModel'];
        if (!is_numeric($rawModelId)) {
            // Non-numeric values are coerced to 0 by PromptService and
            // therefore silently disable the override. Skip — nothing
            // to validate.
            return;
        }

        $modelId = (int) $rawModelId;
        if ($modelId <= 0) {
            return;
        }

        $model = $this->modelRepository->find($modelId);
        if (!$model || 1 !== $model->getActive()) {
            throw new InvalidPromptModelException($modelId);
        }

        if (in_array(strtoupper($model->getTag()), self::EMBEDDING_TAGS, true)) {
            $this->embeddingChangeGuard->assertCanChange($user);
        }
    }
}
