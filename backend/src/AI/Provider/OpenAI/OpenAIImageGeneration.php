<?php

namespace App\AI\Provider\OpenAI;

use App\AI\Exception\ProviderException;
use Psr\Log\LoggerInterface;

/**
 * OpenAI image generation for OpenAIProvider: DALL-E on the Images API, the
 * gpt-image family on the generations endpoint (native cURL, the quality
 * mapped to what the model accepts), edits of attached images on the
 * Responses API with the image tool, variations and masked edits; an answer
 * in prose instead of an image is a NoImageException that keeps the text.
 */
final class OpenAIImageGeneration
{
    public function __construct(
        private readonly OpenAIApiClient $api,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function generateImage(string $prompt, array $options = []): array
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'dall-e-3';
            $inputImages = $options['images'] ?? [];

            switch ($this->selectImageApi($model, $inputImages)) {
                case 'responses':
                    return $this->generateImageWithResponsesApi($prompt, $inputImages, $options);
                case 'gpt_image':
                    return $this->generateImageWithGptImage1($prompt, $options, $model);
            }

            // DALL-E models use Images API
            $requestOptions = [
                'model' => $model,
                'prompt' => $prompt,
                'n' => $options['n'] ?? 1,
                'size' => $options['size'] ?? '1024x1024',
            ];

            // DALL-E 3 specific options
            if ('dall-e-3' === $model) {
                $requestOptions['quality'] = $options['quality'] ?? 'standard'; // standard or hd
                $requestOptions['style'] = $options['style'] ?? 'vivid'; // vivid or natural
            }

            $this->logger->info('OpenAI: Generating image', [
                'model' => $model,
                'prompt_length' => strlen($prompt),
            ]);

            $response = $this->api->sdk()->images()->create($requestOptions);

            $images = [];
            foreach ($response['data'] as $image) {
                $images[] = [
                    'url' => $image['url'] ?? null,
                    'b64_json' => $image['b64_json'] ?? null,
                    'revised_prompt' => $image['revised_prompt'] ?? null,
                ];
            }

            return $images;
        } catch (ProviderException $e) {
            // A text-only reply (and any other provider outcome) already
            // carries its context. Wrapping it here drops the quoted reply
            // and the model, so the chat shows the generic generation error.
            throw $e;
        } catch (\Exception $e) {
            // Check for content policy violations
            if (false !== stripos($e->getMessage(), 'content_policy')
                || false !== stripos($e->getMessage(), 'safety')) {
                throw new ProviderException('Content policy violation: The prompt was rejected by OpenAI safety system', 'openai', ['prompt' => substr($prompt, 0, 100)]);
            }

            throw new ProviderException('OpenAI image generation error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Generate image using gpt-image-* family via Image Generations API.
     *
     * @see https://platform.openai.com/docs/guides/image-generation
     */
    private function generateImageWithGptImage1(string $prompt, array $options, string $model): array
    {
        try {
            $this->logger->info('OpenAI: Generating image with '.$model, [
                'prompt_length' => strlen($prompt),
            ]);

            $requestBody = [
                'model' => $model,
                'prompt' => $prompt,
                'n' => $options['n'] ?? 1,
                'size' => $options['size'] ?? '1024x1024',
            ];

            $quality = $this->gptImageQuality($model, $options['quality'] ?? null);
            if (null !== $quality) {
                $requestBody['quality'] = $quality;
            }
            if (isset($options['background'])) {
                $requestBody['background'] = $options['background'];
            }

            $key = $this->api->key();

            [$responseBody, $httpCode, $curlError] = $this->api->curlPostJson('https://api.openai.com/v1/images/generations', $key, json_encode($requestBody), 120);

            if ($curlError) {
                throw new \Exception('cURL error: '.$curlError);
            }

            if (200 !== $httpCode) {
                $this->logger->error('OpenAI '.$model.': HTTP error', [
                    'http_code' => $httpCode,
                    'response' => substr((string) $responseBody, 0, 500),
                ]);
                throw new \Exception('HTTP '.$httpCode.': '.$responseBody);
            }

            $response = json_decode((string) $responseBody, true);
            if (!$response || !isset($response['data'])) {
                throw new \Exception('Failed to parse JSON response');
            }

            $images = [];
            foreach ($response['data'] as $item) {
                $base64 = $item['b64_json'] ?? null;
                $url = $item['url'] ?? null;

                if (!$url && $base64) {
                    $url = 'data:image/png;base64,'.$base64;
                }

                $images[] = [
                    'url' => $url,
                    'b64_json' => $base64,
                    'revised_prompt' => $item['revised_prompt'] ?? null,
                ];
            }

            if (empty($images)) {
                $this->logger->error('OpenAI '.$model.': No images in response', [
                    'response' => $responseBody,
                ]);
                throw new ProviderException($model.' returned no images. Response format may have changed.', 'openai');
            }

            return $images;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI '.$model.' error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Decide which OpenAI image endpoint to dispatch a request to.
     *
     * - `responses`  : Responses API with the `image_generation` tool (pic2pic with reference images).
     * - `gpt_image`  : Images Generations API for the gpt-image-* family (1, 1.5, 2, …).
     * - `dalle`      : Legacy Images API via the OpenAI PHP client (dall-e-2, dall-e-3).
     *
     * @param string[] $inputImages Absolute paths to reference images, empty for text-to-image
     */
    private function selectImageApi(string $model, array $inputImages): string
    {
        if (!empty($inputImages) && $this->supportsResponsesApi($model)) {
            return 'responses';
        }

        if (str_starts_with($model, 'gpt-image-')) {
            return 'gpt_image';
        }

        return 'dalle';
    }

    private function supportsResponsesApi(string $model): bool
    {
        if (str_starts_with($model, 'gpt-image-')) {
            return true;
        }

        $responsesModels = ['gpt-5', 'gpt-5-mini', 'gpt-5.2', 'gpt-4.1', 'gpt-4o'];

        foreach ($responsesModels as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate image using OpenAI Responses API with input images (pic2pic).
     *
     * @param string   $prompt     Text instruction
     * @param string[] $imagePaths Absolute paths to input images
     *
     * @see https://developers.openai.com/api/docs/guides/image-generation
     */
    private function generateImageWithResponsesApi(string $prompt, array $imagePaths, array $options = []): array
    {
        try {
            $model = $options['model'] ?? 'gpt-image-2.5-flare';
            $responsesModel = $this->pickResponsesModel($model);

            $imageDataUrls = [];
            foreach ($imagePaths as $imgPath) {
                $data = file_get_contents($imgPath);
                if (false === $data) {
                    throw new \Exception('Failed to read image: '.basename($imgPath));
                }
                $mime = mime_content_type($imgPath) ?: 'image/png';
                $imageDataUrls[] = 'data:'.$mime.';base64,'.base64_encode($data);
            }

            $requestBody = $this->buildImageEditRequest($prompt, $model, $imageDataUrls, $options);

            $this->logger->info('OpenAI: Pic2pic via Responses API', [
                'model' => $responsesModel,
                'image_model' => $model,
                'image_count' => \count($imagePaths),
                'prompt_length' => \strlen($prompt),
                'quality' => $requestBody['tools'][0]['quality'] ?? null,
            ]);

            $key = $this->api->key();

            $response = $this->api->postJson('https://api.openai.com/v1/responses', $requestBody, $key, 180);
            $httpCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);

            if (200 !== $httpCode) {
                $this->logger->error('OpenAI Responses API: HTTP error', [
                    'http_code' => $httpCode,
                    'response' => substr((string) $responseBody, 0, 500),
                ]);
                throw new \Exception('HTTP '.$httpCode.': '.$responseBody);
            }

            $response = json_decode((string) $responseBody, true);
            if (!$response || !isset($response['output'])) {
                throw new \Exception('Failed to parse Responses API response');
            }

            $images = [];
            foreach ($response['output'] as $output) {
                if ('image_generation_call' === ($output['type'] ?? null) && !empty($output['result'])) {
                    $images[] = [
                        'url' => 'data:image/png;base64,'.$output['result'],
                        'b64_json' => $output['result'],
                        'revised_prompt' => $output['revised_prompt'] ?? $prompt,
                        // What the model rendered, e.g. 1536x1024: the edit
                        // request leaves the size to the model.
                        'size' => is_string($output['size'] ?? null) ? $output['size'] : null,
                    ];
                }
            }

            if (empty($images)) {
                $exception = ProviderException::noImage('openai', (string) $model, $this->responsesMessageText($response['output']), null);
                $this->logger->error('OpenAI Responses API: No images in output', [
                    'output_types' => array_column($response['output'] ?? [], 'type'),
                ] + $exception->logContext());
                throw $exception;
            }

            return $images;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI Responses API pic2pic error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Text of the assistant `message` output item: what the model said when
     * it answered in prose instead of calling the image tool.
     *
     * @param array<mixed> $output Responses API `output` list
     */
    private function responsesMessageText(array $output): ?string
    {
        foreach ($output as $item) {
            if (!is_array($item) || 'message' !== ($item['type'] ?? null)) {
                continue;
            }
            foreach ($item['content'] ?? [] as $part) {
                if (is_array($part) && is_string($part['text'] ?? null) && '' !== trim($part['text'])) {
                    return $part['text'];
                }
            }
        }

        return null;
    }

    /**
     * Responses API body for an edit of attached images. Pure: the caller
     * reads and encodes the images.
     *
     * No `size` is sent, so the model keeps the orientation of the photo.
     * No `input_fidelity` either: gpt-image-2 and later always edit at high
     * input fidelity and must not get the key.
     *
     * @param list<string>         $imageDataUrls `data:` URLs of the attached images
     * @param array<string, mixed> $options       quality
     *
     * @return array{model: string, input: list<array<string, mixed>>, tools: list<array<string, mixed>>}
     */
    private function buildImageEditRequest(string $prompt, string $model, array $imageDataUrls, array $options): array
    {
        $content = [['type' => 'input_text', 'text' => $prompt]];
        foreach ($imageDataUrls as $dataUrl) {
            $content[] = ['type' => 'input_image', 'image_url' => $dataUrl];
        }

        // GPT Image 2.5 must be named on the tool; omitting `model` lets the
        // Responses API pick a default that is not the catalog row the user
        // selected.
        $tool = ['type' => 'image_generation', 'model' => $model];
        $quality = $this->gptImageQuality($model, $options['quality'] ?? null);
        if (null !== $quality) {
            $tool['quality'] = $quality;
        }

        return [
            'model' => $this->pickResponsesModel($model),
            'input' => [['role' => 'user', 'content' => $content]],
            'tools' => [$tool],
        ];
    }

    /**
     * The quality tier to send to a gpt-image model, or null when the caller
     * asked for none. Legacy values map onto the gpt-image tiers
     * (standard → medium, hd → high); a tier the model does not accept is
     * sent as high, the tier media billing assumes for an unknown value.
     */
    private function gptImageQuality(string $model, mixed $requested): ?string
    {
        if (null === $requested) {
            return null;
        }

        $quality = strtolower((string) $requested);
        $quality = match ($quality) {
            'standard' => 'medium',
            'hd' => 'high',
            default => $quality,
        };

        if (!in_array($quality, $this->allowedGptImageQualities($model), true)) {
            $this->logger->warning('OpenAI '.$model.': Unsupported quality value, defaulting to high', [
                'provided' => $requested,
            ]);

            return 'high';
        }

        return $quality;
    }

    /**
     * Quality values the Images API accepts for this gpt-image model.
     * GPT Image 2.5 adds `xhigh` / `max`; earlier rows only accept
     * low / medium / high / auto. Sending a 2.5-only tier to gpt-image-1
     * is a provider 400, so those stay off the older allow-list.
     *
     * @return list<string>
     */
    private function allowedGptImageQualities(string $model): array
    {
        if (str_starts_with($model, 'gpt-image-2.5')) {
            return ['low', 'medium', 'high', 'xhigh', 'max', 'auto'];
        }

        return ['low', 'medium', 'high', 'auto'];
    }

    /**
     * Pick the mainline model to use with the Responses API image generation tool.
     * Image-specific models (gpt-image-*) need a mainline model wrapper.
     *
     * The wrapper is GPT-5.6 Terra, the successor ModelCatalog::RETIREMENTS[30]
     * records for gpt-4.1: the catalog stopped carrying gpt-4.1 on 2026-07-27, so
     * that wrapper billed tokens against a model with no price row.
     */
    private function pickResponsesModel(string $imageModel): string
    {
        if (str_starts_with($imageModel, 'gpt-image-')) {
            return 'gpt-5.6-terra';
        }

        return $imageModel;
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // A remote URL goes through the shared HTTP client. A local path
            // is the file the caller already has, which file_get_contents reads.
            $imageContent = $this->imageBytes($imageUrl);
            if (false === $imageContent) {
                throw new \Exception('Failed to download image from URL');
            }

            $tmpFile = tmpfile();
            fwrite($tmpFile, $imageContent);
            $tmpPath = stream_get_meta_data($tmpFile)['uri'];

            $response = $this->api->sdk()->images()->variation([
                'image' => fopen($tmpPath, 'r'),
                'n' => $count,
                'size' => '1024x1024',
            ]);

            fclose($tmpFile);

            $variations = [];
            foreach ($response['data'] as $image) {
                $variations[] = [
                    'url' => $image['url'] ?? null,
                    'b64_json' => $image['b64_json'] ?? null,
                ];
            }

            return $variations;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI image variations error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Bytes of an image the caller named. An http(s) URL is fetched through
     * the shared client. Anything else is a path on disk.
     */
    private function imageBytes(string $location): string|false
    {
        if (1 === preg_match('#\Ahttps?://#i', $location)) {
            try {
                return $this->api->getBytes($location);
            } catch (\Throwable) {
                return false;
            }
        }

        return file_get_contents($location);
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Download images. A remote URL uses the shared HTTP client; a
            // local path is read from disk, as the edit and variation tests do.
            $imageContent = $this->imageBytes($imageUrl);
            $maskContent = $this->imageBytes($maskUrl);

            if (false === $imageContent || false === $maskContent) {
                throw new \Exception('Failed to download image or mask');
            }

            // Create temp files
            $tmpImage = tmpfile();
            $tmpMask = tmpfile();
            fwrite($tmpImage, $imageContent);
            fwrite($tmpMask, $maskContent);
            $tmpImagePath = stream_get_meta_data($tmpImage)['uri'];
            $tmpMaskPath = stream_get_meta_data($tmpMask)['uri'];

            $response = $this->api->sdk()->images()->edit([
                'image' => fopen($tmpImagePath, 'r'),
                'mask' => fopen($tmpMaskPath, 'r'),
                'prompt' => $prompt,
                'n' => 1,
                'size' => '1024x1024',
            ]);

            fclose($tmpImage);
            fclose($tmpMask);

            return $response['data'][0]['url'] ?? '';
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI image edit error: '.$e->getMessage(), 'openai');
        }
    }
}
