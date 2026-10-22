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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The personal page: meeting notes the signed-in person started.
 */
#[Route('/api/v1/plugins/synascriber/me', name: 'api_plugin_synascriber_me_')]
#[OA\Tag(name: 'synaScriber Plugin')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly Settings $settings,
    ) {
    }

    #[Route('/sessions', name: 'sessions', methods: ['GET'])]
    #[OA\Get(summary: 'Meeting notes I started or received, newest first')]
    #[OA\Response(response: 200, description: 'List of sessions with their files')]
    public function sessions(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'unauthenticated', 'message' => 'Sign in first.'], 401);
        }

        try {
            $list = $this->sessions->forUser($user);
        } catch (SessionException $e) {
            return $this->json(['error' => $e->errorCode, 'message' => $e->getMessage(), 'sessions' => []], 'not_configured' === $e->errorCode ? 200 : $e->httpStatus);
        }

        return $this->json([
            'enabled' => $this->settings->isEnabled(),
            'admin' => $user->isAdmin(),
            'sessions' => array_map(fn (array $s): array => $this->sessions->view($s, $user), $list),
        ]);
    }
}
