<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Exception\ChatFailureReason;
use App\AI\Exception\ProviderException;
use App\AI\Exception\UpstreamRefusal;
use App\Model\AccountLanguage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a provider / processing failure into a localized, non-leaky message
 * for the user, optionally with an admin-only diagnostics block.
 */
final readonly class ChatErrorPresenter
{
    private const DOMAIN = 'ai_errors';

    /** Synaplan Demo refusal codes with a sentence of their own; any other `freeapi_*` code gets `freeapi.unavailable`. */
    private const REFUSALS = [
        'freeapi_throttled' => 'freeapi.throttled',
        'freeapi_limit_reached' => 'freeapi.limit_reached',
        'freeapi_full_today' => 'freeapi.full_today',
        'freeapi_expired' => 'freeapi.expired',
        'freeapi_paused' => 'freeapi.paused',
        'freeapi_blocked' => 'freeapi.blocked',
        'freeapi_upgrade_required' => 'freeapi.upgrade_required',
        'freeapi_not_included' => 'freeapi.not_included',
    ];

    public function __construct(
        private TranslatorInterface $translator,
        private ChatFailureClassifier $classifier,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function present(\Throwable $e, string $lang, bool $includeDiagnostics = false): ChatErrorView
    {
        $locale = $this->normalizeLocale($lang);
        $reason = $this->classifier->classify($e);
        $adminDetail = $includeDiagnostics ? $this->buildAdminDiagnostics($e, $locale) : null;

        $refusal = UpstreamRefusal::codeOf($e);
        if (null !== $refusal) {
            // Recovery follows the caller's role. Diagnostics are separate:
            // a webhook or tool result can tell an admin to add a provider
            // without attaching the raw upstream error.
            return new ChatErrorView(
                $reason,
                $this->refusalText($refusal, $locale, $this->authorization->isGranted('ROLE_ADMIN')),
                $adminDetail,
                false,
                $e->getMessage(),
            );
        }

        $userText = $this->translator->trans($reason->translationKey(), [], self::DOMAIN, $locale);
        if ($reason->suggestsOtherModel()) {
            $userText .= "\n\n".$this->translator->trans('hint.retry_other_model', [], self::DOMAIN, $locale);
        }

        return new ChatErrorView(
            $reason,
            $userText,
            $adminDetail,
            $reason->suggestsOtherModel(),
            $e->getMessage(),
        );
    }

    /**
     * A refusal is final for the turn, so another model of the same gateway
     * would fail alike; the recovery is an own provider, which only admins
     * can add.
     */
    private function refusalText(string $code, string $locale, bool $isAdmin): string
    {
        $key = self::REFUSALS[$code] ?? 'freeapi.unavailable';
        $recovery = $this->translator->trans($isAdmin ? 'freeapi.recovery.admin' : 'freeapi.recovery.user', [], self::DOMAIN, $locale);

        return $this->translator->trans($key, [], self::DOMAIN, $locale).' '.$recovery;
    }

    /**
     * Reconstruct a view from a MessageProcessor `success: false` result.
     *
     * Prefers the original exception when the caller attached it; otherwise
     * rebuilds a {@see ProviderException} from the raw error string and
     * any structured context so classification still has status/type/code.
     *
     * @param array<string, mixed> $result
     */
    public function presentFromResult(array $result, string $lang, bool $includeDiagnostics = false): ChatErrorView
    {
        $exception = $result['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            return $this->present($exception, $lang, $includeDiagnostics);
        }

        $message = (string) ($result['error'] ?? 'Failed to process message');
        $provider = is_string($result['provider'] ?? null) ? $result['provider'] : 'unknown';
        $context = is_array($result['context'] ?? null) ? $result['context'] : null;
        $status = 0;
        if (isset($context['status_code']) && is_numeric($context['status_code'])) {
            $status = (int) $context['status_code'];
        }

        return $this->present(
            new ProviderException($message, $provider, $context, $status),
            $lang,
            $includeDiagnostics,
        );
    }

    public function classify(\Throwable $e): ChatFailureReason
    {
        return $this->classifier->classify($e);
    }

    /**
     * A catalog exists for every supported locale; anything else falls back to
     * English so an unknown `BLANG` never renders as a missing-key placeholder.
     */
    private function normalizeLocale(string $lang): string
    {
        $normalized = strtolower(substr(trim($lang), 0, 2));

        return in_array($normalized, AccountLanguage::SUPPORTED, true) ? $normalized : 'en';
    }

    private function buildAdminDiagnostics(\Throwable $e, string $lang): string
    {
        $lines = [];

        if ($e instanceof ProviderException) {
            $lines[] = 'Provider: '.$e->getProviderName();
            $status = $e->getUpstreamStatus();
            if (null !== $status) {
                $lines[] = 'Status: '.$status;
            }
        }

        $code = $e->getCode();
        if (0 !== $code && '' !== (string) $code) {
            $lines[] = 'Code: '.$code;
        }

        $lines[] = 'Error: '.$e->getMessage();

        $previous = $e->getPrevious();
        if (null !== $previous) {
            $lines[] = 'Cause: '.$previous->getMessage();
        }

        if ($e instanceof ProviderException) {
            $ctx = $e->getContext();
            if (!empty($ctx)) {
                $encoded = json_encode($ctx, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                if (false !== $encoded) {
                    $lines[] = 'Context: '.$encoded;
                }
            }
        }

        $label = $this->translator->trans('admin.diagnostics', [], self::DOMAIN, $lang);

        return $label."\n".implode("\n", $lines);
    }
}
