<?php

namespace App\Controller;

use App\AI\Exception\NoSpeakableTextException;
use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\TtsTextSanitizer;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/stream', name: 'stream', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/tts/stream',
        summary: 'Stream TTS audio via configured provider',
        description: 'Streams audio from the user\'s configured TTS provider (Piper, OpenAI, Google). The content type depends on the provider. The client must send the language of the text being spoken so Piper (and other providers) select the matching voice.',
        security: [['Bearer' => []]],
        tags: ['Text to Speech']
    )]
    #[OA\Parameter(name: 'text', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'language', in: 'query', required: true, description: 'Language of the spoken text (e.g. de, en). Required so the provider can select the matching voice.', schema: new OA\Schema(type: 'string', example: 'de'))]
    #[OA\Parameter(name: 'voice', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'format', in: 'query', required: false, description: 'Requested audio format (mp3, opus, aac, flac). Providers fall back to their own default when omitted. Piper streams audio/webm by default or for webm/opus, and answers any other format with one complete audio/wav body. Always read the response Content-Type.', schema: new OA\Schema(type: 'string', example: 'mp3'))]
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

        $language = trim((string) $request->query->get('language', ''));
        if ('' === $language) {
            return $this->json(['error' => 'Language is required'], Response::HTTP_BAD_REQUEST);
        }

        // Emptiness gate only — facade sanitizes again before synthesis (#2283).
        if (empty(trim(TtsTextSanitizer::sanitize((string) $text)))) {
            return $this->json(['error' => 'No speakable text provided'], Response::HTTP_BAD_REQUEST);
        }

        $voice = $request->query->get('voice');
        $format = $request->query->get('format');
        $speed = (float) $request->query->get('speed', '1.0');
        $speed = max(0.25, min(4.0, $speed));

        $options = array_filter([
            'voice' => $voice,
            'format' => $format,
            'speed' => $speed,
        ], fn ($v) => null !== $v);

        try {
            $result = $this->aiFacade->synthesizeStream((string) $text, $language, $user->getId(), $options);
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
        } catch (NoSpeakableTextException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('TTS audio streaming failed', [
                'exception' => $e,
            ]);

            return $this->json(['error' => 'Audio generation failed. Please try again or use a different model.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
