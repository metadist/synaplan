<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\TtsTextSanitizer;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api/v1/tts', name: 'api_tts_')]
#[OA\Tag(name: 'Text to Speech')]
class TtsController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $ttsUrl,
    ) {
    }

    #[Route('/stream', name: 'stream', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/tts/stream',
        summary: 'Stream TTS audio',
        description: 'Stream audio from TTS service (Opus/WebM)',
        security: [['Bearer' => []]],
        tags: ['Text to Speech']
    )]
    #[OA\Parameter(name: 'text', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'voice', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'language', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    public function streamAudio(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $text = $request->query->get('text');
        if (empty($text)) {
            return $this->json(['error' => 'Text is required'], Response::HTTP_BAD_REQUEST);
        }

        // Sanitize text to remove artifacts like <think> tags or memory badges
        $text = TtsTextSanitizer::sanitize($text);

        if (empty(trim($text))) {
            return $this->json(['error' => 'No speakable text provided'], Response::HTTP_BAD_REQUEST);
        }

        $voice = $request->query->get('voice');
        $language = $request->query->get('language');

        try {
            $response = $this->httpClient->request('GET', $this->ttsUrl.'/api/tts', [
                'query' => [
                    'text' => $text,
                    'voice' => $voice,
                    'language' => $language,
                    'stream' => 'true',
                ],
                'buffer' => false,
            ]);

            if (200 !== $response->getStatusCode()) {
                // Try to get error content
                $content = $response->getContent(false);

                return $this->json(['error' => 'TTS service error: '.$content], $response->getStatusCode());
            }

            return new StreamedResponse(function () use ($response) {
                foreach ($this->httpClient->stream($response) as $chunk) {
                    echo $chunk->getContent();
                    flush();
                }
            }, 200, [
                'Content-Type' => 'audio/webm',
                'X-Accel-Buffering' => 'no',
                'Cache-Control' => 'no-cache',
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'TTS proxy failed: '.$e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
