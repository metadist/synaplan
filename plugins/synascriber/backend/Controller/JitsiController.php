<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Controller;

use App\Entity\User;
use OpenApi\Attributes as OA;
use Plugin\SynaScriber\Service\SessionException;
use Plugin\SynaScriber\Service\SessionService;
use Plugin\SynaScriber\Service\Settings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Called by the loader inside Jitsi, as the signed-in person (Keycloak bearer
 * token); /silent is public, it is where Keycloak sends the browser back.
 */
#[Route('/api/v1/plugins/synascriber/jitsi', name: 'api_plugin_synascriber_jitsi_')]
#[OA\Tag(name: 'synaScriber Plugin')]
final class JitsiController extends AbstractController
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly Settings $settings,
    ) {
    }

    #[Route('/state', name: 'state', methods: ['GET'])]
    #[OA\Get(summary: 'Whether meeting notes are on here, the offered languages and folder, and the room\'s active notes')]
    #[OA\Parameter(name: 'room', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'State for the button and the dialog')]
    public function state(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->error(new SessionException('unauthenticated', 'Sign in first.', 401));
        }
        if (!$this->settings->isEnabled()) {
            return $this->json(['enabled' => false]);
        }

        $room = (string) $request->query->get('room', '');
        try {
            $session = $this->sessions->forRoom($room);
            $recent = null === $session ? $this->sessions->recentForRoom($user, $room) : null;
        } catch (SessionException $e) {
            return $this->error($e);
        }

        return $this->json([
            'enabled' => true,
            'user' => ['id' => $user->getId(), 'name' => $user->getDisplayName()],
            'languages' => $this->settings->languages(),
            'defaultLanguage' => $this->settings->defaultLanguage(),
            'defaultFolder' => $this->settings->defaultFolder(),
            'shared' => $this->settings->shareWithParticipants(),
            'session' => null === $session ? null : $this->sessions->view($session, $user),
            'recent' => null === $recent ? null : $this->sessions->view($recent, $user),
        ]);
    }

    #[Route('/sessions', name: 'start', methods: ['POST'])]
    #[OA\Post(summary: 'Start meeting notes in a room')]
    #[OA\RequestBody(content: new OA\JsonContent(required: ['room', 'language'], properties: [
        new OA\Property(property: 'room', type: 'string', example: 'weekly-standup'),
        new OA\Property(property: 'language', type: 'string', example: 'de'),
        new OA\Property(property: 'folder', type: 'string', example: 'Meetings'),
    ]))]
    #[OA\Response(response: 201, description: 'Notes started')]
    #[OA\Response(response: 409, description: 'Notes are already on in this meeting')]
    public function start(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->error(new SessionException('unauthenticated', 'Sign in first.', 401));
        }
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->error(new SessionException('invalid_request', 'Expected a JSON body with room and language.', 400));
        }

        try {
            $session = $this->sessions->start(
                $user,
                (string) ($body['room'] ?? ''),
                (string) ($body['language'] ?? $this->settings->defaultLanguage()),
                isset($body['folder']) ? (string) $body['folder'] : null,
            );
        } catch (SessionException $e) {
            return $this->error($e, $user);
        }

        return $this->json(['session' => $this->sessions->view($session, $user)], 'failed' === $session['state'] ? 502 : 201);
    }

    #[Route('/sessions/{ref}/stop', name: 'stop', methods: ['POST'], requirements: ['ref' => '[a-f0-9]{16}|[A-Za-z0-9_-]{22}'])]
    #[OA\Post(summary: 'Stop meeting notes; the transcript is saved within a minute')]
    #[OA\Response(response: 200, description: 'Notes are stopping')]
    public function stop(string $ref, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->error(new SessionException('unauthenticated', 'Sign in first.', 401));
        }

        try {
            return $this->json(['session' => $this->sessions->view($this->sessions->stop($ref, $user), $user)]);
        } catch (SessionException $e) {
            return $this->error($e);
        }
    }

    #[Route('/silent', name: 'silent', methods: ['GET'])]
    #[OA\Get(summary: 'Where Keycloak returns after the Jitsi button\'s sign-in: hands the code to the Jitsi window and closes')]
    #[OA\Response(response: 200, description: 'The page; only the configured Jitsi may embed it')]
    #[OA\Response(response: 404, description: 'No Jitsi address is configured')]
    public function silent(): Response
    {
        $origin = $this->settings->jitsiOrigin();
        if ('' === $origin) {
            throw $this->createNotFoundException('No Jitsi address is configured for meeting notes.');
        }

        $html = <<<'HTML'
            <!doctype html>
            <html lang="en">
              <head>
                <meta charset="utf-8" />
                <title>Meeting notes sign-in</title>
              </head>
              <body>
                <script>
                  (function () {
                    var origin = %s
                    var target = window.opener || (window.parent !== window ? window.parent : null)
                    if (target) {
                      target.postMessage({ type: 'synascriber-auth', search: window.location.search }, origin)
                    }
                    if (window.opener) {
                      window.close()
                    }
                  })()
                </script>
              </body>
            </html>
            HTML;

        return new Response(sprintf($html, json_encode($origin, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => 'frame-ancestors '.$origin,
            'Cache-Control' => 'no-store',
        ]);
    }

    private function error(SessionException $e, ?User $viewer = null): JsonResponse
    {
        $body = ['error' => $e->errorCode, 'message' => $e->getMessage()];
        if (isset($e->details['session']) && is_array($e->details['session'])) {
            $body['session'] = $this->sessions->view($e->details['session'], $viewer);
        }

        return $this->json($body, $e->httpStatus);
    }
}
