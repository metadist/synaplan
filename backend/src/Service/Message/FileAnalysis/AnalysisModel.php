<?php

declare(strict_types=1);

namespace App\Service\Message\FileAnalysis;

use App\Service\ModelConfigService;

/**
 * The model an analysis answers with: the model picked for Again, else the
 * task prompt's model, else the user's default for the first capability in
 * the list that has one.
 */
final readonly class AnalysisModel
{
    private function __construct(
        public ?int $id,
        public ?string $provider,
        public ?string $name,
        private ?int $effectiveUserId,
    ) {
    }

    public static function choose(ModelConfigService $models, AnalysisContext $context, string ...$capabilities): self
    {
        $effectiveUserId = $models->getEffectiveUserIdForMessage($context->message);
        $requested = $context->classification['model_id'] ?? null;
        $id = null !== $requested ? (int) $requested : $context->promptModelId();
        foreach ($capabilities as $capability) {
            if (null !== $id) {
                break;
            }
            $id = $models->getDefaultModel($capability, $effectiveUserId);
        }

        if (!$id) {
            return new self($id, null, null, $effectiveUserId);
        }

        return new self($id, $models->getProviderForModel($id), $models->getModelName($id), $effectiveUserId);
    }

    /**
     * @return array{provider: ?string, model: ?string, max_tokens: int}
     */
    public function options(int $maxTokens): array
    {
        return ['provider' => $this->provider, 'model' => $this->name, 'max_tokens' => $maxTokens];
    }

    /**
     * @return array<string, mixed>
     */
    public function logContext(AnalysisContext $context): array
    {
        return [
            'model_id' => $this->id,
            'provider' => $this->provider,
            'model' => $this->name,
            'user_id' => $context->message->getUserId(),
            'effective_user_id' => $this->effectiveUserId,
        ];
    }

    /**
     * Result metadata; the provider and model that actually answered win over
     * the ones that were requested.
     *
     * @param list<array<string, mixed>> $files
     *
     * @return array<string, mixed>
     */
    public function metadata(mixed $answeredProvider, mixed $answeredModel, array $files, string $analysisType): array
    {
        return [
            'provider' => $answeredProvider ?? $this->provider ?? 'unknown',
            'model' => $answeredModel ?? $this->name ?? 'unknown',
            'model_id' => $this->id,
            'analyzed_file' => AnalysisFileResolver::describe($files),
            'analyzed_file_count' => count($files),
            'analysis_type' => $analysisType,
        ];
    }
}
