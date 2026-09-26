<?php

declare(strict_types=1);

namespace App\AI\Messages;

use App\Entity\ApiKey;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Security\ApiKeyScope;
use App\Service\ModelConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Computer-level desktop jobs omit `model` and expect the server to pick one.
 * Only a paired desktop key gets the account chat model. Any other caller
 * that omits the model still fails closed.
 */
final readonly class DesktopOmittedModel
{
    public function __construct(
        private ModelConfigService $modelConfig,
        private ModelRepository $modelRepository,
    ) {
    }

    public function applies(Request $request, ?string $modelString): bool
    {
        if (null !== $modelString && '' !== trim($modelString)) {
            return false;
        }

        $key = $request->attributes->get('api_key');

        return $key instanceof ApiKey && ApiKeyScope::isPairedDesktop($key->getScopes());
    }

    public function providerIdFor(User $user): ?string
    {
        $modelId = $this->modelConfig->getDefaultModel('CHAT', $user->getId());
        if (null === $modelId) {
            return null;
        }

        $model = $this->modelRepository->find($modelId);
        if (!$model instanceof Model || 1 !== $model->getActive()) {
            return null;
        }

        $providerId = $model->getProviderId();
        if ('' !== $providerId) {
            return $providerId;
        }

        $name = $model->getName();

        return '' !== $name ? $name : null;
    }
}
