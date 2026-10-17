<?php

namespace App\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Phase 5 of a media turn: the user's limit for the media type (IMAGES,
 * VIDEOS, AUDIOS) and, where billing enforces it, the monthly cost budget.
 * Media spend counts toward the budget here too, which also covers the
 * worker and multitask paths (the chat entry checks it first).
 */
final readonly class MediaTurnBudget
{
    public function __construct(
        private EntityManagerInterface $em,
        private RateLimitService $rateLimitService,
        private PremiumFeatureGate $premiumFeatureGate,
        private LoggerInterface $logger,
    ) {
    }

    public function user(Message $message): ?User
    {
        return $this->em->getRepository(User::class)->find($message->getUserId());
    }

    public function refusal(MediaTurnRequest $request, ?User $user): ?MediaRefusal
    {
        if (null === $user) {
            return null;
        }

        $mediaAction = $request->mediaAction();
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, $mediaAction);
        if (!$rateLimitCheck['allowed']) {
            $errorMessage = "Rate limit exceeded for {$mediaAction}. Used: {$rateLimitCheck['used']}/{$rateLimitCheck['limit']}";
            $this->logger->warning('MediaGenerationHandler: Rate limit exceeded', [
                'user_id' => $request->message->getUserId(),
                'action' => $mediaAction,
                'used' => $rateLimitCheck['used'],
                'limit' => $rateLimitCheck['limit'],
            ]);

            return new MediaRefusal($errorMessage, [
                'error' => 'rate_limit_exceeded',
                'action' => $mediaAction,
                'used' => $rateLimitCheck['used'],
                'limit' => $rateLimitCheck['limit'],
            ]);
        }

        // Cost-budget backstop: media spend (incl. the +markup) counts toward
        // the user's monthly budget. Primary enforcement is at the chat entry
        // (StreamController); this also covers worker/multitask media paths.
        if ($this->premiumFeatureGate->isCostBudgetEnforced()) {
            $budgetCheck = $this->rateLimitService->checkCostBudget($user);
            if (!$budgetCheck['allowed']) {
                $lang = $request->language();
                $budgetMessage = 'de' === $lang
                    ? 'Dein monatliches Nutzungsbudget ist aufgebraucht. Du kannst zusätzliches Guthaben in 100-EUR-Schritten aufladen, um weiterzumachen.'
                    : 'Your monthly usage budget is used up. You can top up in EUR 100 steps to keep generating.';

                return new MediaRefusal($budgetMessage, [
                    'error' => 'cost_budget_exceeded',
                    'code' => 'COST_BUDGET_EXCEEDED',
                    'limit_type' => 'monthly',
                    'budget' => $budgetCheck['budget'],
                    'used' => $budgetCheck['used_cost'],
                    'remaining' => $budgetCheck['remaining'],
                    'topup_available' => true,
                ]);
            }
        }

        return null;
    }
}
