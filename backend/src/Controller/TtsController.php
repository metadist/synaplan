<?php

namespace App\Controller;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\TtsTextSanitizer;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/tts', name: 'api_tts_')]
#[OA\Tag(name: 'Text to Speech')]
class TtsController extends AbstractController
{
    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
    ) {
    }

    #[Route('/stream', name: 'stream', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/tts/stream',
        summary: 'Stream TTS audio via configured provider',
        description: 'Streams audio from the user\'s configured TTS provider (Piper, OpenAI, Google). The content type depends on the provider.',
        security: [['Bearer' => []]],
        tags: ['Text to Speech']
    )]
    #[OA\Parameter(name: 'text', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'voice', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'language', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'format', in: 'query', required: false, description: 'Audio format (mp3, opus, aac, flac) — only for OpenAI', schema: new OA\Schema(type: 'string', default: 'mp3'))]
    #[OA\Parameter(name: 'speed', in: 'query', required: false, schema: new OA\Schema(type: 'number', default: 1.0))]
    public function streamAudio(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $text = $request->query->get('text');
        if (empty($text)) {
            return $this->json(['error' => 'Text is required'], Response::HTTP_BAD_REQUEST);
        }

        $text = TtsTextSanitizer::sanitize($text);

        if (empty(trim($text))) {
            return $this->json(['error' => 'No speakable text provided'], Response::HTTP_BAD_REQUEST);
        }

        $voice = $request->query->get('voice');
        $language = $request->query->get('language');
        $format = $request->query->get('format', 'mp3');
        $speed = (float) $request->query->get('speed', '1.0');

        $ttsModelId = $this->modelConfigService->getDefaultModel('TEXT2SOUND', $user->getId());
        $ttsProvider = $ttsModelId ? $this->modelConfigService->getProviderForModel($ttsModelId) : null;

        $options = array_filter([
            'voice' => $voice,
            'language' => $language,
            'format' => $format,
            'speed' => $speed,
            'provider' => $ttsProvider ? strtolower($ttsProvider) : null,
        ]);

        try {
            $result = $this->aiFacade->synthesizeStream($text, $user->getId(), $options);
            $generator = $result['generator'];
            $contentType = $result['contentType'];

            return new StreamedResponse(function () use ($generator) {
                foreach ($generator as $chunk) {
                    echo $chunk;
                    flush();
                }
            }, 200, [
                'Content-Type' => $contentType,
                'X-Accel-Buffering' => 'no',
                'Cache-Control' => 'no-cache',
                'X-TTS-Provider' => $result['provider'],
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'TTS stream failed: '.$e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
