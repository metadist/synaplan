<?php

declare(strict_types=1);

namespace App\Service\Message\FileAnalysis;

use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * Describes images with the vision model. Vision providers take one image per
 * request and do not stream, so a multi-image upload is analysed one image at
 * a time and the descriptions are combined into one reply (#978). A failed
 * image becomes its own failure line; only a batch where every image failed
 * raises.
 */
final readonly class ImageAnalysis
{
    private const MAX_TOKENS = 4000;

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private FileAnalysisFailureCopy $failures,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    /**
     * @param list<array<string, mixed>> $images
     *
     * @return array<string, mixed>
     */
    public function analyze(AnalysisContext $context, array $images): array
    {
        $model = AnalysisModel::choose($this->modelConfigService, $context, 'PIC2TEXT');
        $this->logger->info('FileAnalysisHandler: Using Vision model for image(s)', $model->logContext($context) + [
            'image_count' => count($images),
        ]);

        $perImage = $this->analyzeEach($context, $images, $model);
        $this->throwIfEveryImageFailed($perImage);
        $content = $this->combine($perImage, $images);

        $context->notify('complete', 'Analysis complete.');

        $firstSuccess = null;
        foreach ($perImage as $entry) {
            if (isset($entry['content'])) {
                $firstSuccess = $entry;
                break;
            }
        }

        return $context->reply->deliver(
            $content,
            $model->metadata($firstSuccess['provider'] ?? null, $firstSuccess['model'] ?? null, $images, 'vision'),
        );
    }

    /**
     * One vision call per image; each entry carries `content` on success or
     * `error` + `message` on failure, so a partial reply can be rendered.
     *
     * @param list<array<string, mixed>> $images
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeEach(AnalysisContext $context, array $images, AnalysisModel $model): array
    {
        $userPrompt = $context->message->getText();
        $prompt = !empty($userPrompt)
            ? $userPrompt
            : (count($images) > 1
                ? 'Please describe this image in detail. The user uploaded several images in the same message — describe each one independently.'
                : 'Please describe this image in detail.');
        $prompt .= $context->languageDirective();

        $results = [];
        foreach ($images as $index => $image) {
            $context->notify('analyzing', count($images) > 1
                ? sprintf('Analyzing image %d of %d...', $index + 1, count($images))
                : 'Analyzing image...');

            $fullPath = $this->uploadDir.'/'.$image['path'];
            if (!file_exists($fullPath)) {
                $this->logger->error('FileAnalysisHandler: File not found on disk', [
                    'path' => $image['path'],
                    'full_path' => $fullPath,
                    'image_index' => $index,
                ]);
                $missing = new \RuntimeException('File not found: '.$image['name']);
                $results[] = ['name' => $image['name'], 'error' => 'file_not_found', 'message' => $this->failures->forException($missing, $context->classification), 'exception' => $missing];

                continue;
            }

            try {
                $analysis = $this->aiFacade->analyzeImage($image['path'], $prompt, $context->message->getUserId(), $model->options(self::MAX_TOKENS));
                $results[] = [
                    'name' => $image['name'],
                    'content' => $analysis['content'] ?? '',
                    'provider' => $analysis['provider'] ?? null,
                    'model' => $analysis['model'] ?? null,
                ];
            } catch (\Exception $e) {
                $this->logger->error('FileAnalysisHandler: Vision analysis failed', [
                    'error' => $e->getMessage(),
                    'file' => $image['name'],
                    'image_index' => $index,
                ]);
                $results[] = ['name' => $image['name'], 'error' => 'analysis_failed', 'message' => $this->failures->forException($e, $context->classification), 'exception' => $e];
            }
        }

        return $results;
    }

    /**
     * One image keeps the legacy single-description output.
     *
     * @param list<array<string, mixed>> $results
     * @param list<array<string, mixed>> $images
     */
    private function combine(array $results, array $images): string
    {
        if (1 === count($results)) {
            return $results[0]['content'] ?? $results[0]['message'] ?? $this->failures->imageFailure();
        }

        $parts = [];
        foreach ($results as $index => $entry) {
            $position = $index + 1;
            $name = $entry['name'] ?? ($images[$index]['name'] ?? 'image '.$position);
            $body = $entry['content'] ?? $entry['message'] ?? $this->failures->imageFailure();
            $parts[] = "### Image {$position}: {$name}\n\n{$body}";
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function throwIfEveryImageFailed(array $results): void
    {
        foreach ($results as $entry) {
            if (isset($entry['content'])) {
                return;
            }
        }
        foreach ($results as $entry) {
            if (($entry['exception'] ?? null) instanceof \Throwable) {
                throw $entry['exception'];
            }
        }
    }
}
