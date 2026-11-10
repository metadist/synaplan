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
     * token's claims (`sub`, or `preferred_username` for a cloud that names
     * people by login), never a value the caller picks freely. Empty means
     * the subject.
     */
    public function externalId(Request $request, string $requested): string
    {
        $claims = $request->attributes->get(OidcBearerAuthenticator::CLAIMS_ATTRIBUTE);
        $subject = is_array($claims) && is_string($claims['sub'] ?? null) ? $claims['sub'] : '';
        if ('' === $subject) {
            throw new CloudDeliveryException(Response::HTTP_UNAUTHORIZED, 'unauthorized');
        }
        $requested = trim($requested);
        if ('' === $requested) {
            return $subject;
        }
        $login = is_string($claims['preferred_username'] ?? null) ? $claims['preferred_username'] : '';
        if ($requested !== $subject && $requested !== $login) {
            throw new CloudDeliveryException(Response::HTTP_FORBIDDEN, 'external_id_mismatch');
        }

        return $requested;
    }
}
