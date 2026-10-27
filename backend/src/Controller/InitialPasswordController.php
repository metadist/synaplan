<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Billing\PayFirst\PayFirstConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A password is optional while pay first is on. This is its own controller
 * so the profile controller stays under the file-size gate.
 */
#[OA\Tag(name: 'Profile')]
final class InitialPasswordController extends AbstractController
{
    public function __construct(
        private readonly PayFirstConfig $payFirst,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[OA\Post(
        path: '/api/v1/profile/password/initial',
        operationId: 'setInitialPassword',
        summary: 'Set a password on an account that does not have one',
        description: 'Only while pay first is on. An account that already has a password, or that signs in through an organisation, is refused.',
        security: [['Bearer' => []]],
        tags: ['Profile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['newPassword'],
            properties: [new OA\Property(property: 'newPassword', type: 'string', format: 'password', example: 'NewSecure1')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Password set', content: new OA\JsonContent(
                required: ['success'],
                properties: [new OA\Property(property: 'success', type: 'boolean', example: true)]
            )),
            new OA\Response(response: 400, description: 'The password is too short or too simple (PASSWORD_TOO_WEAK)'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'This account already has a password or is managed elsewhere (PASSWORD_ALREADY_SET)'),
            new OA\Response(response: 404, description: 'Pay first is not active (PAY_FIRST_OFF)'),
            new OA\Response(response: 415, description: 'The body must be sent as application/json (PASSWORD_JSON_REQUIRED)'),
        ]
    )]
    #[Route('/api/v1/profile/password/initial', name: 'api_profile_set_initial_password', methods: ['POST'])]
    public function set(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->payFirst->isActive()) {
            return $this->json(['success' => false, 'error' => 'Not found.', 'code' => 'PAY_FIRST_OFF'], Response::HTTP_NOT_FOUND);
        }
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->json(['success' => false, 'error' => 'Send the request as JSON.', 'code' => 'PASSWORD_JSON_REQUIRED'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }
        if ($user->isManagedExternally() || $user->canChangePassword()) {
            return $this->json([
                'success' => false,
                'error' => 'This account already has a way to sign in.',
                'code' => 'PASSWORD_ALREADY_SET',
            ], Response::HTTP_FORBIDDEN);
        }
        $body = json_decode($request->getContent(), true);
        $newPassword = is_array($body) && is_string($body['newPassword'] ?? null) ? $body['newPassword'] : '';
        if (strlen($newPassword) < 8 || 1 !== preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $newPassword)) {
            return $this->json([
                'success' => false,
                'error' => 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, and a number.',
                'code' => 'PASSWORD_TOO_WEAK',
            ], Response::HTTP_BAD_REQUEST);
        }
        $user->setPw($this->passwordHasher->hashPassword($user, $newPassword));
        $user->setMustChangePassword(false);
        $this->userRepository->save($user);

        return $this->json(['success' => true]);
    }
}
