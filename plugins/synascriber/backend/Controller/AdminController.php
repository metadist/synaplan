<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Controller;

use App\Entity\User;
use OpenApi\Attributes as OA;
use Plugin\SynaScriber\Service\ProsodyClient;
use Plugin\SynaScriber\Service\Settings;
use Plugin\SynaScriber\Service\WhisperClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Administrator settings and the honest status lines of the plugin page.
 */
#[Route('/api/v1/plugins/synascriber/admin', name: 'api_plugin_synascriber_admin_')]
#[OA\Tag(name: 'synaScriber Plugin')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ProsodyClient $prosody,
        private readonly WhisperClient $whisper,
    ) {
    }

    #[Route('/status', name: 'status', methods: ['GET'])]
    #[OA\Get(summary: 'Settings (secrets as set / not set) and connection checks')]
    #[OA\Response(response: 200, description: 'Settings and checks')]
    public function status(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->denied($user)) {
            return $denied;
        }

        return $this->json($this->statusBody());
    }

    #[Route('/settings', name: 'settings', methods: ['PUT'])]
    #[OA\Put(summary: 'Update settings')]
    #[OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'enabled', type: 'boolean'),
        new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'default_language', type: 'string'),
        new OA\Property(property: 'default_folder', type: 'string'),
        new OA\Property(property: 'stt_url', type: 'string'),
        new OA\Property(property: 'prosody_url', type: 'string'),
        new OA\Property(property: 'prosody_secret', type: 'string'),
        new OA\Property(property: 'owner_user_id', type: 'integer'),
        new OA\Property(property: 'timezone', type: 'string'),
    ]))]
    #[OA\Response(response: 200, description: 'Saved; returns the new status')]
    #[OA\Response(response: 422, description: 'A value was rejected')]
    public function update(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->denied($user)) {
            return $denied;
        }
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'invalid_request', 'message' => 'Expected a JSON object of settings.'], 400);
        }

        try {
            $this->settings->update($body);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'invalid_setting', 'message' => $e->getMessage()], 422);
        }

        return $this->json($this->statusBody());
    }

    /**
     * @return array<string, mixed>
     */
    private function statusBody(): array
    {
        $owner = $this->settings->ownerUserId();

        return [
            'settings' => $this->settings->publicView(),
            'checks' => [
                'owner' => $owner > 0,
                'jitsi' => '' !== $this->settings->prosodyUrl() && $this->prosody->healthy(),
                'speech' => $this->whisper->healthy(),
                'secret' => '' !== $this->settings->prosodySecret(),
            ],
        ];
    }

    private function denied(?User $user): ?JsonResponse
    {
        if (!$user instanceof User || !$user->isAdmin()) {
            return $this->json(['error' => 'not_allowed', 'message' => 'Only administrators manage meeting notes.'], 403);
        }

        return null;
    }
}
