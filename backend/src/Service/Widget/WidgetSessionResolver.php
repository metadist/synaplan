<?php

declare(strict_types=1);

namespace App\Service\Widget;

use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * The visitor's session for a public widget request. Only the signed-in
 * owner can open the two special modes: test mode (a `test_` session the
 * statistics leave out) and internal mode (the owner chatting from the
 * dashboard, without the visitor limits).
 */
final readonly class WidgetSessionResolver
{
    public function __construct(
        private WidgetSessionService $sessions,
        private Security $security,
        private EntityManagerInterface $em,
    ) {
    }

    public function resolve(Request $request, Widget $widget, string $sessionId): WidgetSession
    {
        $user = $this->security->getUser();
        $ownerMatch = $user instanceof User && $user->getId() === $widget->getOwnerId();

        $isTestMode = $ownerMatch && 'true' === $request->headers->get('X-Widget-Test-Mode');
        $isInternalMode = !$isTestMode && $ownerMatch && 'true' === $request->headers->get('X-Widget-Internal-Mode');

        $session = $this->sessions->getOrCreateSession($widget->getWidgetId(), $sessionId, $isTestMode);

        if ($isInternalMode && $session->isAiMode()) {
            $session->setMode(WidgetSession::MODE_INTERNAL);
            // Durable at once: the request may still stop at a limit or the owner lookup.
            $this->em->flush();
        }

        return $session;
    }
}
