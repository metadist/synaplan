<?php

declare(strict_types=1);

namespace App\Service\Widget\Turn;

use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Service\Widget\WidgetDomainGuard;
use App\Service\Widget\WidgetRequestRefused;
use App\Service\Widget\WidgetService;
use App\Service\Widget\WidgetSessionResolver;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First stage of a widget turn: admits the visitor's message or refuses it
 * with the answer the widget shows. In order: the body, the widget and its
 * owner's limits, the embedding domain, the visitor's session, the session's
 * message limit (not for the owner's internal session) and the owner. The
 * session's first message records the visitor's country.
 */
final readonly class WidgetTurnGate
{
    public function __construct(
        private WidgetService $widgets,
        private WidgetDomainGuard $domains,
        private WidgetSessionResolver $sessionResolver,
        private WidgetSessionService $sessions,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws WidgetRequestRefused
     */
    public function admit(string $widgetId, Request $request): WidgetTurn
    {
        $data = json_decode($request->getContent(), true);
        if (null === $data) {
            throw WidgetRequestRefused::json(['error' => 'Invalid JSON in request body'], Response::HTTP_BAD_REQUEST);
        }
        if (!is_array($data) || empty($data['sessionId']) || empty($data['text']) || !is_scalar($data['sessionId']) || !is_scalar($data['text'])) {
            throw WidgetRequestRefused::json(['error' => 'Missing required fields: sessionId, text'], Response::HTTP_BAD_REQUEST);
        }

        $widget = $this->widgets->getWidgetById($widgetId)
            ?? throw WidgetRequestRefused::json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        if (!$this->widgets->isWidgetActive($widget)) {
            throw WidgetRequestRefused::json(['error' => 'Widget is not active', 'reason' => 'owner_limits_exceeded'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $config = $widget->getConfig();
        $this->domains->check($config, $request, $widget->getOwnerId());

        $session = $this->sessionResolver->resolve($request, $widget, (string) $data['sessionId']);
        $this->recordCountry($session, $request);
        $heldForOperator = in_array($session->getMode(), [WidgetSession::MODE_HUMAN, WidgetSession::MODE_WAITING], true);

        if (!$session->isInternalMode()) {
            $this->checkSessionLimit($session, $config);
        }

        return new WidgetTurn(
            widget: $widget,
            owner: $this->owner($widget),
            session: $session,
            config: $config,
            text: (string) $data['text'],
            files: !empty($data['files']) && is_array($data['files']) ? $data['files'] : [],
            chatId: !empty($data['chatId']) ? (int) $data['chatId'] : null,
            externalUserId: isset($data['externalUserId']) && \is_string($data['externalUserId']) ? trim($data['externalUserId']) : '',
            heldForOperator: $heldForOperator,
        );
    }

    /** Cloudflare's geolocation header, kept from the session's first message on. */
    private function recordCountry(WidgetSession $session, Request $request): void
    {
        if (null !== $session->getCountry()) {
            return;
        }
        $country = $request->headers->get('CF-IPCountry');
        if (null !== $country && '' !== $country) {
            $session->setCountry($country);
            $this->em->flush();
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function checkSessionLimit(WidgetSession $session, array $config): void
    {
        $limit = $this->sessions->checkSessionLimit($session, (int) ($config['messageLimit'] ?? WidgetSessionService::DEFAULT_MAX_MESSAGES));
        if (!$limit['allowed']) {
            throw WidgetRequestRefused::json(['error' => 'Rate limit exceeded', 'reason' => $limit['reason'], 'remaining' => $limit['remaining'], 'retryAfter' => $limit['retry_after']], Response::HTTP_TOO_MANY_REQUESTS);
        }
    }

    private function owner(Widget $widget): User
    {
        $ownerId = $widget->getOwnerId();
        $owner = $ownerId ? $this->em->getRepository(User::class)->find($ownerId) : null;
        if (!$owner instanceof User) {
            throw WidgetRequestRefused::json(['error' => 'Widget owner not found'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $owner;
    }
}
