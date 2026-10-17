<?php

namespace App\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Phase 3 of a media turn: the model. A slash command fixes the media type;
 * an Again pick wins over a task prompt's pinned model, which wins over the
 * install's default for the request (text or image to picture, video or
 * sound). A retired model follows its successor. Without a media type the
 * turn asks the user which media they want.
 */
final readonly class MediaModelResolver
{
    public function __construct(
        private ModelConfigService $modelConfigService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $classification
     *
     * @return MediaModelChoice|null null when the media type cannot be told
     */
    public function choose(Message $message, array $classification, MediaPrompt $prompt): ?MediaModelChoice
    {
        $mediaType = 'image'; // default

        // Check if this is a slash command (e.g., /pic, /vid)
        $topic = $classification['topic'] ?? null;
        $isSlashCommand = false;
        if ('tools:pic' === $topic) {
            $mediaType = 'image';
            $isSlashCommand = true;
            $this->logger->info('MediaGenerationHandler: Detected /pic command, forcing image generation');
        } elseif ('tools:vid' === $topic) {
            $mediaType = 'video';
            $isSlashCommand = true;
            $this->logger->info('MediaGenerationHandler: Detected /vid command, forcing video generation');
        } elseif ('tools:tts' === $topic) {
            $mediaType = 'audio';
            $isSlashCommand = true;
            $this->logger->info('MediaGenerationHandler: Detected /tts command, forcing audio generation');
        }

        // Priority: Again model_id > Task-prompt aiModel > DB default
        $promptMetadata = $classification['prompt_metadata'] ?? [];
        $promptAiModel = (isset($promptMetadata['aiModel']) && (int) $promptMetadata['aiModel'] > 0)
            ? (int) $promptMetadata['aiModel']
            : null;

        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = (int) $classification['model_id'];
            $this->logger->info('MediaGenerationHandler: Using classification override model', [
                'model_id' => $modelId,
            ]);

            return $this->picked($modelId, $isSlashCommand, $mediaType, $prompt->references);
        }

        if ($promptAiModel) {
            $this->logger->info('MediaGenerationHandler: Using task-prompt aiModel override', [
                'model_id' => $promptAiModel,
            ]);

            return $this->picked($promptAiModel, $isSlashCommand, $mediaType, $prompt->references);
        }

        return $this->byDefault($message, $mediaType, $isSlashCommand, $prompt);
    }

    /**
     * Reads the chosen model row; a retired model follows its successor.
     */
    public function resolve(Message $message, MediaModelChoice $choice): MediaModel
    {
        $modelId = $choice->modelId;
        $provider = $choice->provider;
        $modelName = $choice->modelName;

        // Resolve model ID to provider + model name + config
        $modelConfig = [];
        if ($modelId) {
            $model = $this->em->getRepository(\App\Entity\Model::class)->find($modelId);

            // An "Again" pick or a task prompt's pinned aiModel is a stored copy
            // of a BID and is read ahead of the default chain, so a retired one
            // must follow its successor here or it reaches a provider that has
            // shut the model down.
            if ($model?->isRetired()) {
                $replacementId = $this->modelConfigService->replacementForRetiredModel(
                    (int) $modelId,
                    $this->capabilityForMediaRequest($choice->mediaType, $choice->references->isPic2Pic(), $choice->references->hasVideoReferenceImage()),
                    $this->modelConfigService->getEffectiveUserIdForMessage($message),
                );
                $model = null !== $replacementId ? $this->em->getRepository(\App\Entity\Model::class)->find($replacementId) : null;
                $modelId = null !== $model ? $replacementId : null;
                if (null === $model) {
                    $provider = null;
                    $modelName = null;
                }
            }

            if ($model) {
                $provider = strtolower($model->getService());
                $modelName = $model->getProviderId() ?: $model->getName();
                $modelConfig = $model->getJson();
            }

            $this->logger->info('MediaGenerationHandler: Resolved model', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName,
            ]);
        }

        return new MediaModel($modelId, $provider, $modelName, $modelConfig);
    }

    /**
     * A picked model sets the media type by its tag, unless a slash command fixed it.
     */
    private function picked(int $modelId, bool $isSlashCommand, string $mediaType, MediaReferences $references): MediaModelChoice
    {
        [$mediaType, $provider, $modelName] = $this->resolveMediaTypeFromModelId($modelId, $isSlashCommand, $mediaType, null, null);

        return new MediaModelChoice($modelId, $mediaType, $provider, $modelName, $isSlashCommand, $references);
    }

    /**
     * The install's default for what the request needs.
     */
    private function byDefault(Message $message, string $mediaType, bool $isSlashCommand, MediaPrompt $prompt): ?MediaModelChoice
    {
        $references = $prompt->references;
        $attachedImagePaths = $references->attachedImagePaths;
        $imageUrlsInText = $references->imageUrlsInText;
        $editSource = $references->editSource;
        $isPic2Pic = $references->isPic2Pic();
        $hasVideoReferenceImage = $references->hasVideoReferenceImage();
        $promptMediaType = $prompt->mediaType;

        $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);

        if ($isSlashCommand) {
            if ('video' === $mediaType) {
                // `/vid` with an image reference is an image-to-video request
                // (animate the photo) → use the IMG2VID default; otherwise a
                // text-to-video request → TEXT2VID. The reference can be an
                // attached file OR a public image URL pasted in the text.
                $modelId = $hasVideoReferenceImage
                    ? $this->modelConfigService->getDefaultModel('IMG2VID', $effectiveUserId)
                    : $this->modelConfigService->getDefaultModel('TEXT2VID', $effectiveUserId);
            } elseif ('audio' === $mediaType) {
                $modelId = $this->modelConfigService->getDefaultModel('TEXT2SOUND', $effectiveUserId);
            } else {
                $modelId = $this->modelConfigService->getDefaultModel('TEXT2PIC', $effectiveUserId);
            }
            $this->logger->info('MediaGenerationHandler: Using default model for slash command', [
                'media_type' => $mediaType,
                'is_pic2pic' => $isPic2Pic,
                'model_id' => $modelId,
            ]);
        } elseif ($hasVideoReferenceImage && 'video' === $promptMediaType) {
            // Image-to-video: the user supplied an image (attached file OR a
            // public image URL in the text) AND asked for a video/animation.
            // This MUST win over the pic2pic-image branch below (an image
            // reference alone would otherwise force an image edit). Routes to
            // the IMG2VID default (an image-to-video model); the reference is
            // published/forwarded as image_url in the video generation block.
            $modelId = $this->modelConfigService->getDefaultModel('IMG2VID', $effectiveUserId);
            $mediaType = 'video';
            $this->logger->info('MediaGenerationHandler: Image-to-video detected, using IMG2VID default model', [
                'model_id' => $modelId,
                'attached_images' => count($attachedImagePaths),
                'image_urls_in_text' => count($imageUrlsInText),
            ]);
        } elseif ($isPic2Pic) {
            $modelId = $this->modelConfigService->getDefaultModel('PIC2PIC', $effectiveUserId);
            $mediaType = 'image';

            // The reference came from the conversation, not from the user.
            // An install without a PIC2PIC default would answer "no model
            // configured" for a request that used to produce a picture, so
            // drop the reference and generate from text instead: degraded,
            // never broken. An ATTACHED image keeps the hard error — there
            // the user explicitly asked for an edit.
            if (!$modelId && null !== $editSource) {
                $this->logger->warning('MediaGenerationHandler: no PIC2PIC model configured, falling back to text2pic for the conversation edit', [
                    'edit_source' => $editSource->reference,
                ]);
                $modelId = $this->modelConfigService->getDefaultModel('TEXT2PIC', $effectiveUserId);
                $references = new MediaReferences([], $references->imageUrlsInText, null);
                $attachedImagePaths = [];
            }

            $this->logger->info('MediaGenerationHandler: Pic2pic detected, using PIC2PIC default model', [
                'model_id' => $modelId,
                'image_count' => count($attachedImagePaths),
            ]);
        } elseif ('video' === $promptMediaType) {
            $modelId = $this->modelConfigService->getDefaultModel('TEXT2VID', $effectiveUserId);
            $mediaType = 'video';
            $this->logger->info('MediaGenerationHandler: Using media type hint from extractor (video)');
        } elseif ('audio' === $promptMediaType) {
            $modelId = $this->modelConfigService->getDefaultModel('TEXT2SOUND', $effectiveUserId);
            $mediaType = 'audio';
            $this->logger->info('MediaGenerationHandler: Using media type hint from extractor (audio)');
        } elseif ('image' === $promptMediaType) {
            $modelId = $this->modelConfigService->getDefaultModel('TEXT2PIC', $effectiveUserId);
            $mediaType = 'image';
            $this->logger->info('MediaGenerationHandler: Using media type hint from extractor (image)');
        } else {
            $this->logger->warning('MediaGenerationHandler: Media type not determined from extractor, asking user to clarify', [
                'prompt_preview' => substr($prompt->text, 0, 100),
            ]);

            return null;
        }

        return new MediaModelChoice($modelId, $mediaType, null, null, $isSlashCommand, $references);
    }

    /**
     * The account whose model settings and media jobs the turn runs under.
     */
    public function effectiveUserId(Message $message): ?int
    {
        return $this->modelConfigService->getEffectiveUserIdForMessage($message);
    }

    private function capabilityForMediaRequest(string $mediaType, bool $isPic2Pic, bool $hasVideoReferenceImage): string
    {
        return match ($mediaType) {
            'video' => $hasVideoReferenceImage ? 'IMG2VID' : 'TEXT2VID',
            'audio' => 'TEXT2SOUND',
            default => $isPic2Pic ? 'PIC2PIC' : 'TEXT2PIC',
        };
    }

    /**
     * Resolve media type, provider, and model name from a model ID.
     *
     * @return array{string, ?string, ?string} [$mediaType, $provider, $modelName]
     */
    private function resolveMediaTypeFromModelId(int $modelId, bool $isSlashCommand, string $mediaType, ?string $provider, ?string $modelName): array
    {
        if ($isSlashCommand) {
            return [$mediaType, $provider, $modelName];
        }

        $model = $this->em->getRepository(\App\Entity\Model::class)->find($modelId);
        if ($model) {
            $tag = $model->getTag();
            if ('text2vid' === $tag) {
                $mediaType = 'video';
            } elseif ('text2sound' === $tag) {
                $mediaType = 'audio';
            }
            $provider = $model->getService();
            $modelName = $model->getName();
        }

        return [$mediaType, $provider, $modelName];
    }
}
