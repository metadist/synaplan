<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POST /v1/images/generations: resolve the TEXT2PIC model, generate, meter
 * the images as IMAGES and answer in the OpenAI shape.
 *
 * Nothing is written to this server's storage: `b64_json` carries the bytes,
 * `url` carries the provider URL (or a `data:` URL when the provider only
 * returned bytes). A provider URL is fetched only for `b64_json`, only over
 * https, without redirects, with a size and time limit. The images are metered
 * as soon as the provider returned them, even if delivery fails afterwards.
 */
final readonly class OpenAiImageGenerationService
{
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;
    private const FETCH_TIMEOUT_SECONDS = 60;

    public function __construct(
        private AiFacade $aiFacade,
        private OpenAiGatewayModels $models,
        private RateLimitService $rateLimitService,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $gated the Synaplan Demo gate already applied its own limits
     *
     * @return array{created: int, data: list<array<string, string>>}
     */
    public function create(User $user, OpenAiImageGenerationRequest $request, bool $gated): array
    {
        $userId = (int) $user->getId();
        OpenAiGatewayAdmission::check($this->rateLimitService, $user, 'IMAGES', $gated);

        $model = $this->models->resolve('text2pic', 'TEXT2PIC', $request->model, $userId);
        $provider = strtolower($model->getService());
        $options = [
            'provider' => $provider,
            'model' => $model->getProviderId(),
            'model_id' => (int) $model->getId(),
            'usage_source' => 'OPENAI_API',
            'modelConfig' => $model->getJson(),
            'n' => $request->n,
            'size' => 'auto' === $request->size ? OpenAiImageGenerationRequest::DEFAULT_SIZE : $request->size,
        ];
        if (null !== $request->quality) {
            $options['quality'] = $request->quality;
        }

        try {
            $result = $this->aiFacade->generateImage($request->prompt, $userId, $options);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI-compatible image generation failed', ['user_id' => $userId, 'provider' => $provider, 'error' => $e->getMessage()]);
            throw OpenAiGatewayException::upstream('image');
        }
        $images = \is_array($result['images'] ?? null) ? array_values($result['images']) : [];
        if ([] !== $images) {
            $this->recordUsage($user, $provider, $model->getProviderId(), (int) $model->getId(), \count($images), $options);
        }

        try {
            $data = $this->toOpenAiItems($images, $request->responseFormat);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI-compatible image delivery failed', ['user_id' => $userId, 'provider' => $provider, 'error' => $e->getMessage()]);
            throw OpenAiGatewayException::upstream('image');
        }
        if ([] === $data) {
            throw OpenAiGatewayException::upstream('image');
        }

        return ['created' => time(), 'data' => $data];
    }

    /**
     * @param array<mixed> $images provider items `{url?, b64_json?, revised_prompt?}`
     *
     * @return list<array<string, string>>
     */
    private function toOpenAiItems(array $images, string $format): array
    {
        $items = [];
        foreach ($images as $image) {
            if (!\is_array($image)) {
                continue;
            }
            $url = \is_string($image['url'] ?? null) ? $image['url'] : '';
            $b64 = \is_string($image['b64_json'] ?? null) ? $image['b64_json'] : '';
            if ('' === $b64 && str_starts_with($url, 'data:')) {
                $b64 = substr($url, (int) strpos($url, ',') + 1);
            }

            if ('b64_json' === $format) {
                $item = ['b64_json' => '' !== $b64 ? $b64 : $this->fetchBase64($url)];
            } elseif ('' !== $url) {
                $item = ['url' => $url];
            } elseif ('' !== $b64) {
                $item = ['url' => 'data:image/png;base64,'.$b64];
            } else {
                continue;
            }
            if (\is_string($image['revised_prompt'] ?? null) && '' !== $image['revised_prompt']) {
                $item['revised_prompt'] = $image['revised_prompt'];
            }
            $items[] = $item;
        }

        return $items;
    }

    private function fetchBase64(string $url): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Image provider returned neither bytes nor an https URL.');
        }

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => self::FETCH_TIMEOUT_SECONDS,
            'max_duration' => self::FETCH_TIMEOUT_SECONDS,
            'max_redirects' => 0,
        ]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('Image download answered HTTP %d.', $response->getStatusCode()));
        }

        $bytes = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $bytes .= $chunk->getContent();
            if (\strlen($bytes) > self::MAX_IMAGE_BYTES) {
                $response->cancel();
                throw new \RuntimeException('Image download exceeded the size limit.');
            }
        }

        return base64_encode($bytes);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function recordUsage(User $user, string $provider, string $modelName, int $modelId, int $count, array $options): void
    {
        try {
            \App\Service\Usage\LegacyUsageWriter::record($this->rateLimitService, $user, 'IMAGES', [
                'provider' => $provider,
                'model' => $modelName,
                'model_id' => $modelId,
                'media_usage' => array_filter([
                    'images' => (float) $count,
                    'quality' => $options['quality'] ?? null,
                    'size' => $options['size'],
                ], static fn ($value) => null !== $value),
                'source' => 'OPENAI_API',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI-compatible images: recordUsage failed', ['user_id' => $user->getId(), 'error' => $e->getMessage()]);
        }
    }
}
