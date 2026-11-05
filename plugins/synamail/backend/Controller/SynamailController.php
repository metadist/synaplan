<?php

declare(strict_types=1);

namespace Plugin\Synamail\Controller;

use App\Entity\User;
use App\Service\Plugin\PluginManager;
use OpenApi\Attributes as OA;
use Plugin\Synamail\Service\ContactProfileService;
use Plugin\Synamail\Service\ProfileException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * HTTP for contact profiles. The merge lives in ContactProfileService.
 */
#[Route('/api/v1/user/{userId}/plugins/synamail', name: 'api_plugin_synamail_')]
#[OA\Tag(name: 'Synamail Plugin')]
final class SynamailController extends AbstractController
{
    public function __construct(
        private readonly ContactProfileService $profiles,
        private readonly PluginManager $plugins,
    ) {
    }

    #[Route('/profiles', name: 'profiles_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synamail/profiles',
        summary: 'List all contact profiles',
        security: [['ApiKey' => []]],
        tags: ['Synamail Plugin'],
    )]
    #[OA\Response(response: 200, description: 'All stored profiles, newest first')]
    #[OA\Response(response: 401, description: 'The signed-in person is not this user')]
    #[OA\Response(response: 403, description: 'The profile service is off')]
    public function listProfiles(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user, $userId);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }

        return $this->json(['success' => true, 'profiles' => $this->profiles->list($userId)]);
    }

    #[Route('/profiles/{email}', name: 'profile_get', requirements: ['email' => '[^/]+'], methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synamail/profiles/{email}',
        summary: 'Fetch the rolling profile of one contact',
        security: [['ApiKey' => []]],
        tags: ['Synamail Plugin'],
    )]
    #[OA\Response(response: 200, description: 'The profile, or profile: null when none exists yet')]
    #[OA\Response(response: 400, description: 'The address is not an email')]
    #[OA\Response(response: 401, description: 'The signed-in person is not this user')]
    #[OA\Response(response: 403, description: 'The profile service is off')]
    public function getProfile(int $userId, string $email, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user, $userId);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }

        try {
            $profile = $this->profiles->get($userId, $email);
        } catch (ProfileException $e) {
            return $this->rejected($e);
        }

        return $this->json(['success' => true, 'profile' => $profile]);
    }

    #[Route('/profiles/{email}/update', name: 'profile_update', requirements: ['email' => '[^/]+'], methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synamail/profiles/{email}/update',
        summary: 'Roll one email into the contact profile',
        description: 'Sends the existing profile plus the new email to the chat model and stores the merged result. Counts, dates and the organisation are computed on the server.',
        security: [['ApiKey' => []]],
        tags: ['Synamail Plugin'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['body'],
            properties: [
                new OA\Property(property: 'subject', type: 'string', example: 'Re: demo follow-up'),
                new OA\Property(property: 'body', type: 'string', example: 'Hi, thanks for the call yesterday...'),
                new OA\Property(property: 'date', type: 'string', example: '2026-06-10T14:32:00Z', description: 'ISO 8601 date of the email'),
                new OA\Property(property: 'direction', type: 'string', enum: ['inbound', 'outbound'], example: 'inbound'),
                new OA\Property(property: 'name', type: 'string', example: 'Alice Example'),
            ],
        ),
    )]
    #[OA\Response(response: 200, description: 'The updated profile')]
    #[OA\Response(response: 400, description: 'The address or the body is unusable')]
    #[OA\Response(response: 401, description: 'The signed-in person is not this user')]
    #[OA\Response(response: 403, description: 'The profile service is off')]
    #[OA\Response(response: 502, description: 'The chat model did not return a profile')]
    public function updateProfile(int $userId, string $email, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user, $userId);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }

        $payload = $this->objectPayload($request->getContent());
        if (null === $payload) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $profile = $this->profiles->update($userId, $email, $payload);
        } catch (ProfileException $e) {
            return $this->rejected($e);
        }

        return $this->json(['success' => true, 'profile' => $profile]);
    }

    #[Route('/profiles/{email}', name: 'profile_delete', requirements: ['email' => '[^/]+'], methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/synamail/profiles/{email}',
        summary: 'Delete a contact profile entirely',
        security: [['ApiKey' => []]],
        tags: ['Synamail Plugin'],
    )]
    #[OA\Response(response: 200, description: 'Deletion result')]
    #[OA\Response(response: 400, description: 'The address is not an email')]
    #[OA\Response(response: 401, description: 'The signed-in person is not this user')]
    #[OA\Response(response: 403, description: 'The profile service is off')]
    public function deleteProfile(int $userId, string $email, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user, $userId);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }

        try {
            $deleted = $this->profiles->delete($userId, $email);
        } catch (ProfileException $e) {
            return $this->rejected($e);
        }

        return $this->json(['success' => true, 'deleted' => $deleted]);
    }

    private function guard(?User $user, int $userId): ?JsonResponse
    {
        if (!$user instanceof User || $user->getId() !== $userId) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->profiles->isEnabled($userId)) {
            return $this->json(['success' => false, 'error' => 'The profile service is off'], Response::HTTP_FORBIDDEN);
        }
        if (!$this->installedFor($userId)) {
            return $this->json(['success' => false, 'error' => 'Not installed'], Response::HTTP_NOT_FOUND);
        }

        return null;
    }

    private function installedFor(int $userId): bool
    {
        foreach ($this->plugins->listInstalledPlugins($userId) as $manifest) {
            if ('synamail' === $manifest->name) {
                return true;
            }
        }

        return false;
    }

    private function rejected(ProfileException $e): JsonResponse
    {
        return $this->json(['success' => false, 'error' => $e->getMessage()], $e->httpStatus);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function objectPayload(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
