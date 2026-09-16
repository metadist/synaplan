<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Exception\ChatFailureReason;
use App\AI\Exception\ProviderException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a provider / processing failure into a localized, non-leaky message
 * for the user, optionally with an admin-only diagnostics block.
 */
final readonly class ChatErrorPresenter
{
    private const DOMAIN = 'ai_errors';

    /**
     * Locales we ship catalogs for. Anything else falls back to English so
     * an unknown `BLANG` never renders as a missing-key placeholder.
     *
     * @var list<string>
     */
    private const SUPPORTED_LOCALES = ['de', 'en', 'es', 'fr', 'tr'];

    public function __construct(
        private TranslatorInterface $translator,
        private ChatFailureClassifier $classifier,
    ) {
    }

    public function present(\Throwable $e, string $lang, bool $includeDiagnostics = false): ChatErrorView
    {
        $locale = $this->normalizeLocale($lang);
        $reason = $this->classifier->classify($e);
        $userText = $this->translator->trans($reason->translationKey(), [], self::DOMAIN, $locale);

        if ($reason->suggestsOtherModel()) {
            $userText .= "\n\n".$this->translator->trans('hint.retry_other_model', [], self::DOMAIN, $locale);
        }

        $adminDetail = $includeDiagnostics ? $this->buildAdminDiagnostics($e, $locale) : null;

        return new ChatErrorView(
            $reason,
            $userText,
            $adminDetail,
            $reason->suggestsOtherModel(),
            $e->getMessage(),
        );
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

    private function normalizeLocale(string $lang): string
    {
        $normalized = strtolower(substr(trim($lang), 0, 2));

        return in_array($normalized, self::SUPPORTED_LOCALES, true) ? $normalized : 'en';
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
