<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Entity\User;
use App\Security\FreeApiGatewayGuard;
use App\Service\RateLimitService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admission check shared by every spending route of the OpenAI-compatible
 * gateway: the per-action rate limit (skipped when the Synaplan Demo gate
 * already applied its own limits) and the account's cost budget, which is
 * never skipped so the Demo account keeps a hard spend backstop in core.
 */
final class OpenAiGatewayAdmission
{
    /**
     * @throws OpenAiGatewayException when the request must not reach a provider
     */
    public static function admit(RateLimitService $rateLimitService, Request $request, User $user, string $action): void
    {
        self::check($rateLimitService, $user, $action, FreeApiGatewayGuard::isGated($request));
    }

    /**
     * @param bool $gated the Synaplan Demo gate already applied its own limits
     *
     * @throws OpenAiGatewayException when the request must not reach a provider
     */
    public static function check(RateLimitService $rateLimitService, User $user, string $action, bool $gated): void
    {
        if (!$gated && !$rateLimitService->checkLimit($user, $action)['allowed']) {
            throw OpenAiGatewayException::rateLimited();
        }
        if (!$rateLimitService->checkCostBudget($user)['allowed']) {
            throw OpenAiGatewayException::budgetExhausted();
        }
    }
}
