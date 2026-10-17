<?php

namespace App\Service\Message\Handler\Media;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\Message\TtsScriptGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Phase 4 of a media turn: what stops it before a provider is asked. A
 * sorter-routed audio turn whose script only repeats the request is answered
 * as chat; a turn without a media type asks which media the user wants; no
 * configured model (#1320), text-to-video on an image-to-video model and
 * image-to-video while APP_URL is not reachable from the internet are
 * refused with an actionable sentence instead of a raw provider error.
 */
final readonly class MediaRequestGuard
{
    public function __construct(
        private AiFacade $aiFacade,
        private MediaUploadStore $uploadStore,
        private LoggerInterface $logger,
        #[Autowire('%env(APP_URL)%')]
        private string $publicBaseUrl = '',
    ) {
    }

    /**
     * Speaking such a script would play the user's own question back as an
     * MP3. `/tts` and Again re-runs are exempt: there the user chose to hear
     * that exact text.
     */
    public function answersAsChat(MediaModelChoice $choice, MediaPrompt $prompt, Message $message): bool
    {
        return 'audio' === $choice->mediaType
            && !$choice->isSlashCommand
            && null === $message->getMeta('media_prompt_override')
            && TtsScriptGuard::echoesInstruction($prompt->text, $message->getText());
    }

    /**
     * @param array<string, mixed> $classification
     */
    public function undeterminedMediaType(array $classification): MediaRefusal
    {
        $lang = $classification['language'] ?? 'en';
        $clarification = 'de' === $lang
            ? 'Ich konnte nicht erkennen, welche Art von Medium du erstellen möchtest. '
                .'Bitte verwende einen der folgenden Befehle: `/pic` für Bilder, `/vid` für Videos, `/tts` für Audio.'
            : 'I couldn\'t determine what type of media you want to generate. '
                .'Please use one of the following commands: `/pic` for images, `/vid` for videos, `/tts` for audio.';

        return new MediaRefusal($clarification, ['error' => 'media_type_not_determined']);
    }

    public function refusal(MediaTurnRequest $request): ?MediaRefusal
    {
        return $this->withoutModel($request)
            ?? $this->textToVideoOnAnImageToVideoModel($request)
            ?? $this->imageToVideoBehindAPrivateAppUrl($request);
    }

    /**
     * No model resolved: the operator has no default configured for this
     * media capability (TEXT2PIC/TEXT2VID/TEXT2SOUND/…). Return an
     * actionable error instead of silently routing to a hardcoded OpenAI
     * model, which would bill an unconfigured vendor and break for installs
     * that don't use OpenAI (#1320).
     */
    private function withoutModel(MediaTurnRequest $request): ?MediaRefusal
    {
        $mediaType = $request->mediaType;
        $modelId = $request->model->id;
        $provider = $request->model->provider;

        if (!$provider) {
            $this->logger->warning('MediaGenerationHandler: No model configured for media generation', [
                'media_type' => $mediaType,
                'model_id' => $modelId,
            ]);

            $lang = $request->language();
            $noModelMessage = 'de' === $lang
                ? 'Für die Medienerstellung ist kein Modell konfiguriert. Bitte lege in den Einstellungen ein Standardmodell fest (Bild, Video oder Audio).'
                : 'No model is configured for media generation. Please set a default model (image, video, or audio) in Settings.';

            return new MediaRefusal($noModelMessage, [
                'error' => 'no_media_model_configured',
                'media_type' => $mediaType,
            ]);
        }

        return null;
    }

    /**
     * Guard: a text-to-video request (no attached image) must never be sent
     * to an image-to-video model. i2v models (Higgsfield DoP/Kling/etc.)
     * require a reference frame and the provider rejects the request with
     * "'image_url' is a required property". This happens when an i2v model is
     * configured as the TEXT2VID default. Return an actionable message
     * instead of leaking a raw provider 400 to the user.
     */
    private function textToVideoOnAnImageToVideoModel(MediaTurnRequest $request): ?MediaRefusal
    {
        $mediaType = $request->mediaType;
        $modelId = $request->model->id;
        $provider = $request->model->provider;
        $modelName = $request->model->name;
        $modelConfig = $request->model->config;
        $hasVideoReferenceImage = $request->references->hasVideoReferenceImage();

        if ('video' === $mediaType && !$hasVideoReferenceImage) {
            $requiresReferenceImage = !empty($modelConfig['requires_reference_image'])
                || (!empty($modelConfig['features']) && in_array('image2video', $modelConfig['features'], true));
            if ($requiresReferenceImage) {
                $this->logger->warning('MediaGenerationHandler: text-to-video routed to an image-to-video model without a reference image', [
                    'model_id' => $modelId,
                    'provider' => $provider,
                    'model' => $modelName,
                ]);

                $lang = $request->language();
                $guardMessage = 'de' === $lang
                    ? sprintf('„%s“ ist ein Bild-zu-Video-Modell und benötigt ein Referenzbild. Hänge ein Bild an, um es zu animieren, oder wähle in den Einstellungen ein Text-zu-Video-Modell (z. B. Veo) als Standard für Video.', $modelName)
                    : sprintf('"%s" is an image-to-video model and needs a reference image. Attach an image to animate it, or choose a text-to-video model (e.g. Veo) as your video default in Settings.', $modelName);

                return new MediaRefusal($guardMessage, [
                    'error' => 'text2vid_requires_reference_image',
                    'provider' => $provider,
                    'model' => $modelName,
                ]);
            }
        }

        return null;
    }

    /**
     * Guard: image-to-video requires the remote provider to FETCH the
     * attached source frame from a public http(s) URL (we republish it at
     * APP_URL/api/v1/files/uploads/...). When APP_URL points at a
     * local/private host — the default in local dev (http://localhost:8000)
     * — that URL is unreachable from the provider's servers and the request
     * is rejected with a confusing raw "invalid_image_url" 400. Detect this
     * up front and return an actionable message instead of burning a
     * provider call (and credits) on a request that cannot succeed.
     */
    private function imageToVideoBehindAPrivateAppUrl(MediaTurnRequest $request): ?MediaRefusal
    {
        $mediaType = $request->mediaType;
        $provider = $request->model->provider;
        $modelName = $request->model->name;
        $attachedImagePaths = $request->references->attachedImagePaths;
        $imageUrlsInText = $request->references->imageUrlsInText;

        // Providers implementing SupportsInlineReferenceImage take the reference
        // frame as inline bytes, so they need neither a republished file nor a
        // publicly reachable APP_URL.
        $inlineReferenceImage = 'video' === $mediaType
            && $this->aiFacade->supportsInlineReferenceImage($provider);

        if ('video' === $mediaType && !$inlineReferenceImage && [] !== $attachedImagePaths && [] === $imageUrlsInText && !$this->uploadStore->isPublicBaseUrlReachable()) {
            $base = rtrim($this->publicBaseUrl, '/');
            $this->logger->warning('MediaGenerationHandler: image-to-video blocked — APP_URL is not internet-reachable so the provider cannot fetch the source image', [
                'app_url' => $base,
                'provider' => $provider,
                'model' => $modelName,
            ]);

            $lang = $request->language();
            $guardMessage = 'de' === $lang
                ? sprintf('Für Bild-zu-Video muss dein Bild über eine öffentliche URL an den Anbieter gesendet werden. Die öffentliche Adresse dieses Servers (APP_URL = „%s") ist jedoch ein lokaler/privater Host, den der Anbieter nicht erreichen kann. Setze APP_URL auf eine über das Internet erreichbare URL (z. B. einen Tunnel) oder verwende ein Text-zu-Video-Modell ohne angehängtes Bild.', $base)
                : sprintf('Image-to-video has to send your image to the provider from a public URL, but this server\'s public address (APP_URL = "%s") is a local/private host the provider can\'t reach. Set APP_URL to an internet-reachable URL (e.g. a tunnel), or use a text-to-video model without an attached image.', $base);

            return new MediaRefusal($guardMessage, [
                'error' => 'i2v_requires_public_app_url',
                'provider' => $provider,
                'model' => $modelName,
            ]);
        }

        return null;
    }
}
