<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Security\OidcBearerAuthenticator;
use Symfony\Component\HttpFoundation\Request;

/**
 * The cloud a file came from. Taken from the caller's credential, never from
 * a field the request can set.
 */
final readonly class SourceInstanceResolver
{
    public function __construct(
        private ExternalIdentityRepository $identities,
        private PlatformInstanceRepository $instances,
    ) {
    }

    public function fromRequest(Request $request): ?string
    {
        $key = $request->attributes->get('api_key');
        if ($key instanceof ApiKey) {
            $identity = $this->identities->findOneByApiKeyId((int) $key->getId());

            return $identity instanceof ExternalIdentity && '' !== $identity->getInstanceId()
                ? $identity->getInstanceId()
                : null;
        }

        $matches = $this->activeForBearer($request);

        return 1 === count($matches) ? $matches[0]->getInstanceId() : null;
    }

    /**
     * True when the bearer's client names more than one active cloud, so no
     * single instance can be attributed. That happens when the same cloud was
     * registered twice (a lost state file, a dev stack) and the earlier
     * instance was never revoked. Callers turn it into its own sentence
     * instead of the generic "not linked".
     */
    public function isAmbiguous(Request $request): bool
    {
        return count($this->activeForBearer($request)) > 1;
    }

    /**
     * @return list<PlatformInstance>
     */
    private function activeForBearer(Request $request): array
    {
        $claims = $request->attributes->get(OidcBearerAuthenticator::CLAIMS_ATTRIBUTE);
        if (!is_array($claims)) {
            return [];
        }
        $azp = $claims['azp'] ?? '';
        if (!is_string($azp) || '' === $azp) {
            return [];
        }

        return array_values(array_filter(
            $this->instances->findBy(['oidcClientId' => $azp]),
            static fn (PlatformInstance $instance): bool => $instance->isActive(),
        ));
    }
}
