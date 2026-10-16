<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\GuestSession;
use App\Entity\User;
use App\Entity\WidgetSession;
use App\Service\Chat\GuestChatConfig;
use App\Service\Chat\GuestSessionService;
use App\Service\Widget\WidgetService;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who streams a turn: the signed-in person, a widget visitor (`X-Widget-Id` +
 * `X-Widget-Session` headers, answered as the widget owner), or a guest on
 * the trial (`guestSession` parameter, answered as the processing user).
 */
final readonly class StreamCallerResolver
{
    /**
     * UI locales accepted as a BLANG seed, mirroring the SPA's
     * `supportedLanguages` (frontend/src/i18n/index.ts) and
     * {@see User::getLocale()}. Anything else falls back to `en` so a crafted
     * value cannot poison BMESSAGES.BLANG (varchar(2)).
     */
    private const SUPPORTED_UI_LANGUAGES = ['de', 'en', 'es', 'fr', 'tr'];

    public function __construct(
        private WidgetService $widgetService,
        private WidgetSessionService $widgetSessionService,
        private GuestSessionService $guestSessionService,
        private GuestChatConfig $guestChatConfig,
        private EntityManagerInterface $em,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param InputBag<bool|float|int|string> $params
     *
     * @throws StreamRequestRejected when no caller can be established
     */
    public function resolve(Request $request, InputBag $params, ?User $user): StreamCaller
    {
        $widgetSession = null;
        $guestSession = null;
        $widgetTaskPrompt = null;

        if (!$user) {
            $widgetId = $request->headers->get('X-Widget-Id');
            $sessionId = $request->headers->get('X-Widget-Session');

            if ($widgetId && $sessionId) {
                [$user, $widgetSession, $widgetTaskPrompt] = $this->widgetVisitor($request, $widgetId, $sessionId);
            } else {
                [$user, $guestSession] = $this->guest($params);
            }
        }

        return new StreamCaller(
            user: $user,
            uiLanguage: $this->uiLanguage($params->get('language'), $user),
            clientCountry: $request->headers->get('CF-IPCountry'),
            widgetSession: $widgetSession,
            guestSession: $guestSession,
            widgetTaskPrompt: $widgetTaskPrompt,
        );
    }

    /**
     * @return array{0: User, 1: WidgetSession, 2: string|null}
     */
    private function widgetVisitor(Request $request, string $widgetId, string $sessionId): array
    {
        $widget = $this->widgetService->getWidgetById($widgetId);
        if (!$widget) {
            throw StreamRequestRejected::json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->widgetService->isWidgetActive($widget)) {
            throw StreamRequestRejected::json(['error' => 'Widget is not active', 'reason' => 'owner_limits_exceeded'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Only the widget owner may mark a session as a test, so nobody can
        // keep their visits out of the statistics.
        $isValidatedTestMode = $this->isValidatedTestMode($request, $widget->getOwnerId());
        $widgetSession = $this->widgetSessionService->getOrCreateSession($widgetId, $sessionId, $isValidatedTestMode);

        $limitCheck = $this->widgetSessionService->checkSessionLimit($widgetSession);
        if (!$limitCheck['allowed']) {
            throw StreamRequestRejected::json(['error' => 'Rate limit exceeded', 'reason' => $limitCheck['reason'], 'remaining' => $limitCheck['remaining'], 'retryAfter' => $limitCheck['retry_after']], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $ownerId = $widget->getOwnerId();
        if (!$ownerId) {
            throw StreamRequestRejected::json(['error' => 'Widget owner not found'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $user = $this->em->getRepository(User::class)->find($ownerId);
        if (!$user) {
            throw StreamRequestRejected::json(['error' => 'Widget owner not found'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $taskPrompt = $widget->getTaskPromptTopic();
        $this->logger->info('Widget request authenticated', [
            'widget_id' => $widgetId,
            'session_id' => $sessionId,
            'owner_id' => $user->getId(),
            'task_prompt' => $taskPrompt,
        ]);

        return [$user, $widgetSession, $taskPrompt];
    }

    /**
     * @param InputBag<bool|float|int|string> $params
     *
     * @return array{0: User, 1: GuestSession}
     */
    private function guest(InputBag $params): array
    {
        $guestSessionId = $params->get('guestSession');
        if (!$guestSessionId) {
            throw StreamRequestRejected::json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->guestChatConfig->isEnabled()) {
            // "Trial disabled" is not "session expired": clients must not
            // offer a new trial the instance cannot serve (#1517).
            throw StreamRequestRejected::json(['error' => 'Guest chat is disabled on this instance.', 'code' => GuestChatConfig::DISABLED_CODE], Response::HTTP_FORBIDDEN);
        }

        $guestSessionId = (string) $guestSessionId;
        $guestSession = $this->guestSessionService->getSession($guestSessionId);
        if (!$guestSession || $guestSession->isExpired()) {
            throw StreamRequestRejected::json(['error' => 'Guest session not found or expired'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->guestSessionService->checkLimit($guestSession)) {
            throw StreamRequestRejected::sseEvent('guest_limit_reached', ['message' => 'Guest message limit reached', 'action' => 'signup_required']);
        }

        $user = $this->guestSessionService->getProcessingUser();
        if (!$user) {
            throw StreamRequestRejected::json(['error' => 'Guest mode unavailable'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logger->info('Guest request authenticated', [
            'session_id' => substr($guestSessionId, 0, 12).'...',
            'remaining' => $this->guestSessionService->getRemainingMessages($guestSession),
        ]);

        return [$user, $guestSession];
    }

    /**
     * The inbound BLANG seed: the requested UI locale, then the profile
     * locale, then `en`.
     */
    private function uiLanguage(mixed $requested, User $user): string
    {
        if (is_string($requested)) {
            $normalized = strtolower(trim($requested));
            if (in_array($normalized, self::SUPPORTED_UI_LANGUAGES, true)) {
                return $normalized;
            }
        }

        $profileLocale = strtolower($user->getLocale());
        if (in_array($profileLocale, self::SUPPORTED_UI_LANGUAGES, true)) {
            return $profileLocale;
        }

        return 'en';
    }

    /**
     * A widget test session needs the `X-Widget-Test-Mode: true` header and
     * the signed-in widget owner.
     */
    private function isValidatedTestMode(Request $request, int $widgetOwnerId): bool
    {
        if ('true' !== $request->headers->get('X-Widget-Test-Mode')) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            $this->logger->debug('Test mode rejected: no authenticated user');

            return false;
        }

        if ($user->getId() !== $widgetOwnerId) {
            $this->logger->debug('Test mode rejected: user is not widget owner', [
                'user_id' => $user->getId(),
                'widget_owner_id' => $widgetOwnerId,
            ]);

            return false;
        }

        $this->logger->debug('Test mode validated for widget owner', [
            'user_id' => $user->getId(),
        ]);

        return true;
    }
}
