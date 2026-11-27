<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Exception\CloudDeliveryException;
use App\Repository\PlatformInstanceRepository;
use App\Security\OidcBearerAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves who calls a cloud route: the signed-in person and the active cloud
 * their credential belongs to (an API key bound to an identity row, or a
 * bearer whose client is a registered cloud). A session without a cloud, as a
 * browser has, is refused.
 */
final readonly class CloudCallerResolver
{
    public function __construct(
        private SourceInstanceResolver $sources,
        private PlatformInstanceRepository $instances,
        private OidcCloud $cloud,
    ) {
    }

    public function resolve(Request $request, ?User $user): CloudCaller
    {
        if (!$user instanceof User || null === $user->getId()) {
            throw new CloudDeliveryException(Response::HTTP_UNAUTHORIZED, 'unauthorized');
        }
        if ($this->sources->isAmbiguous($request)) {
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'platform_ambiguous');
        }
        $instanceId = $this->sources->fromRequest($request);
        $instance = null !== $instanceId ? $this->instances->findByInstanceId($instanceId) : null;
        if (!$instance instanceof PlatformInstance) {
            throw new CloudDeliveryException(Response::HTTP_FORBIDDEN, 'platform_disconnected');
        }
        if (!$instance->isActive()) {
            throw new CloudDeliveryException(Response::HTTP_FORBIDDEN, 'instance_pending');
        }

        return new CloudCaller($instance, (int) $user->getId());
    }

    /**
     * The platform user id a person may bind or ask about: one of their own
     * token's claims (`sub`, `preferred_username` for a cloud that names
     * people by login, or the claim the configured cloud names its people by,
     * {@see OidcCloud::$userClaim}), never a value the caller picks freely.
     * Empty means the value of that configured claim, which is the subject
     * unless another claim is set.
     */
    public function externalId(Request $request, string $requested): string
    {
        $claims = $request->attributes->get(OidcBearerAuthenticator::CLAIMS_ATTRIBUTE);
        $subject = is_array($claims) && is_string($claims['sub'] ?? null) ? $claims['sub'] : '';
        if ('' === $subject) {
            throw new CloudDeliveryException(Response::HTTP_UNAUTHORIZED, 'unauthorized');
        }
        $configured = $claims[$this->cloud->userClaim] ?? null;
        $configured = is_string($configured) ? $configured : '';
        $requested = trim($requested);
        if ('' === $requested) {
            if ('' === $configured) {
                throw new CloudDeliveryException(Response::HTTP_UNAUTHORIZED, 'unauthorized');
            }

            return $configured;
        }
        $login = is_string($claims['preferred_username'] ?? null) ? $claims['preferred_username'] : '';
        if ($requested !== $subject && $requested !== $login && $requested !== $configured) {
            throw new CloudDeliveryException(Response::HTTP_FORBIDDEN, 'external_id_mismatch');
        }

        return $requested;
    }
}
