<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * POST /v1/embeddings: resolve the VECTORIZE model, embed in one provider
 * batch, meter the call as EMBEDDINGS and answer in the OpenAI list shape.
 */
final readonly class OpenAiEmbeddingsService
{
    public function __construct(
        private AiFacade $aiFacade,
        private OpenAiGatewayModels $models,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $gated the Synaplan Demo gate already applied its own limits
     *
     * @return array{object: string, data: list<array{object: string, index: int, embedding: list<float>|string}>, model: string, usage: array{prompt_tokens: int, total_tokens: int}}
     */
    public function create(User $user, OpenAiEmbeddingsRequest $request, bool $gated): array
    {
        $userId = (int) $user->getId();
        OpenAiGatewayAdmission::check($this->rateLimitService, $user, 'EMBEDDINGS', $gated);

        $model = $this->models->resolve('vectorize', 'VECTORIZE', $request->model, $userId);
        $provider = strtolower($model->getService());
        $options = ['model' => $model->getProviderId()];
        if (null !== $request->dimensions) {
            $options['dimensions'] = $request->dimensions;
        }

        try {
            $result = $this->aiFacade->embedBatch($request->inputs, $userId, $provider, $options);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI-compatible embeddings failed', ['user_id' => $userId, 'provider' => $provider, 'error' => $e->getMessage()]);
            throw OpenAiGatewayException::upstream('embedding');
        }

        $usage = [
            'prompt_tokens' => $result['usage']['prompt_tokens'],
            'total_tokens' => $result['usage']['total_tokens'],
        ];
        $this->recordUsage($user, $provider, $model->getProviderId(), (int) $model->getId(), $usage, $request);

        $vectors = $result['embeddings'];
        if (\count($vectors) !== \count($request->inputs)) {
            $this->logger->error('OpenAI-compatible embeddings returned a different count', ['user_id' => $userId, 'expected' => \count($request->inputs), 'got' => \count($vectors)]);
            throw OpenAiGatewayException::upstream('embedding');
        }

        $data = [];
        foreach (array_values($vectors) as $index => $vector) {
            $floats = array_map('floatval', array_values($vector));
            $data[] = [
                'object' => 'embedding',
                'index' => $index,
                'embedding' => 'base64' === $request->encodingFormat ? base64_encode(pack('g*', ...$floats)) : $floats,
            ];
        }

        return ['object' => 'list', 'data' => $data, 'model' => $model->getProviderId(), 'usage' => $usage];
    }

    /**
     * @param array{prompt_tokens: int, total_tokens: int} $usage
     */
    private function recordUsage(User $user, string $provider, string $modelName, int $modelId, array $usage, OpenAiEmbeddingsRequest $request): void
    {
        try {
            $this->rateLimitService->recordUsage($user, 'EMBEDDINGS', [
                'usage' => $usage,
                'provider' => $provider,
                'model' => $modelName,
                'model_id' => $modelId,
                'input_bytes' => array_sum(array_map('strlen', $request->inputs)),
                'inputs' => \count($request->inputs),
                'source' => 'OPENAI_API',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI-compatible embeddings: recordUsage failed', ['user_id' => $user->getId(), 'error' => $e->getMessage()]);
        }
    }
}
