<?php

namespace App\Service\Message\Handler;

use App\AI\Exception\ProviderException;

class MediaErrorMessageBuilder
{
    /**
     * Localized copy when a background job exceeds its platform wait budget.
     */
    public function buildTimeoutMessage(string $mediaType, string $lang): string
    {
        return $this->getFailureExplanation('timeout', $mediaType, $lang)
            ?? $this->getGenericMediaError($mediaType, $lang);
    }

    /**
     * Localized copy when the user has hit their concurrent-media-jobs ceiling.
     */
    public function buildTooManyJobsMessage(string $lang): string
    {
        return match ($lang) {
            'de' => 'Du hast bereits die maximale Anzahl gleichzeitiger Medienaufträge laufen. '
                .'Bitte warte, bis einer fertig ist, und versuche es dann erneut.',
            'es' => 'Ya tienes el número máximo de trabajos de medios en curso. '
                .'Espera a que termine uno e inténtalo de nuevo.',
            'tr' => 'Aynı anda çalışan en fazla medya işine ulaştın. '
                .'Lütfen biri bitene kadar bekleyip tekrar dene.',
            default => 'You already have the maximum number of media jobs running. '
                .'Please wait for one to finish, then try again.',
        };
    }

    /**
     * Build a user-friendly, translated error message from the exception.
     *
     * @param bool $includeDiagnostics when true, append a raw technical
     *                                 diagnostics block to the message. This is
     *                                 reserved for ADMIN users so they can see
     *                                 the underlying provider error/cause that is
     *                                 deliberately hidden from regular users.
     */
    public function buildErrorMessage(\Exception $e, string $mediaType, string $lang, bool $includeDiagnostics = false): string
    {
        $message = $this->buildUserFacingMessage($e, $mediaType, $lang);

        if ($includeDiagnostics) {
            $message .= $this->buildAdminDiagnostics($e, $lang);
        }

        return $message;
    }

    /**
     * The localized, non-leaky message shown to every user.
     */
    private function buildUserFacingMessage(\Exception $e, string $mediaType, string $lang): string
    {
        if ($e instanceof ProviderException) {
            $ctx = $e->getContext() ?? [];
            $blockReason = $ctx['block_reason'] ?? null;

            if ($blockReason) {
                return $this->buildContentBlockedMessage(
                    $e->getProviderName(),
                    $blockReason,
                    $ctx['text_response'] ?? null,
                    $mediaType,
                    $lang,
                );
            }
        }

        // Map common, recognisable failure modes onto a clear, actionable
        // message — especially for image-to-video, where "the link to your image
        // could not be opened" is far more useful than a bare "could not be
        // generated". We classify from the (internal) exception WITHOUT ever
        // forwarding its raw text to the user, so no system detail leaks.
        $category = $this->classifyFailure($e);
        if (null !== $category) {
            $explanation = $this->getFailureExplanation($category, $mediaType, $lang);
            if (null !== $explanation) {
                return $explanation;
            }
        }

        return $this->getGenericMediaError($mediaType, $lang);
    }

    /**
     * Raw technical diagnostics appended for ADMIN users only.
     *
     * Regular users get a clean, non-leaky message; admins additionally see the
     * underlying provider, error code, raw message, root cause and any
     * provider-supplied context so they can diagnose WHY a generation failed
     * without digging through worker logs.
     */
    private function buildAdminDiagnostics(\Exception $e, string $lang): string
    {
        $lines = [];

        if ($e instanceof ProviderException) {
            $lines[] = 'Provider: '.$e->getProviderName();
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

        $label = 'de' === $lang ? 'Admin-Diagnose (nur für dich sichtbar)' : 'Admin diagnostics (visible to you only)';

        return "\n\n---\n**".$label."**\n```\n".implode("\n", $lines)."\n```";
    }

    /**
     * Classify an internal exception into a coarse, user-safe failure category.
     * Returns null when nothing specific is recognised (caller falls back to the
     * generic message). The raw message is only inspected here — it is never
     * shown to the user.
     */
    private function classifyFailure(\Exception $e): ?string
    {
        $statusCode = null;
        if ($e instanceof ProviderException) {
            $ctx = $e->getContext() ?? [];
            if (isset($ctx['status_code']) && is_numeric($ctx['status_code'])) {
                $statusCode = (int) $ctx['status_code'];
            }
        }

        if (401 === $statusCode || 403 === $statusCode) {
            return 'auth';
        }
        if (402 === $statusCode) {
            return 'credits';
        }
        if (429 === $statusCode) {
            return 'rate_limit';
        }
        if (404 === $statusCode) {
            return 'model';
        }

        $message = strtolower($e->getMessage());

        // A failed reference-image fetch is the #1 image-to-video failure: the
        // provider could not open the URL we handed it (private host, dead link,
        // a page instead of a direct image file, etc.).
        if (
            (str_contains($message, 'image') && (
                str_contains($message, 'url')
                || str_contains($message, 'fetch')
                || str_contains($message, 'download')
                || str_contains($message, 'invalid')
                || str_contains($message, 'not found')
                || str_contains($message, 'unreachable')
                || str_contains($message, 'access')
            ))
            || str_contains($message, 'invalid_image_url')
        ) {
            return 'image_access';
        }

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'timeout';
        }

        if (str_contains($message, 'api key') || str_contains($message, 'authentication') || str_contains($message, 'unauthorized')) {
            return 'auth';
        }

        if (str_contains($message, 'out of credits') || str_contains($message, 'insufficient') || str_contains($message, 'quota')) {
            return 'credits';
        }

        if (str_contains($message, 'rate limit')) {
            return 'rate_limit';
        }

        return null;
    }

    /**
     * Human-friendly, localized explanation for a failure category. Returns null
     * for an unknown category so the caller can fall back to the generic copy.
     */
    private function getFailureExplanation(string $category, string $mediaType, string $lang): ?string
    {
        $mediaLabelEn = match ($mediaType) {
            'audio' => 'audio',
            'video' => 'video',
            default => 'image',
        };
        $mediaLabelDe = match ($mediaType) {
            'audio' => 'Audio',
            'video' => 'Video',
            default => 'Bild',
        };

        if ('de' === $lang) {
            return match ($category) {
                'image_access' => 'Das von dir verlinkte Bild konnte nicht geöffnet werden. '
                    .'Bitte stelle sicher, dass der Link direkt auf eine öffentlich erreichbare Bilddatei zeigt '
                    .'(Endung .jpg, .jpeg, .png, .webp oder .gif) – nicht auf eine Webseite – und versuche es erneut. '
                    .'Alternativ kannst du das Bild direkt in den Chat hochladen.',
                'timeout' => "Die Erstellung deines {$mediaLabelDe}s hat zu lange gedauert und wurde abgebrochen. "
                    .'Bitte versuche es erneut – bei Videos hilft oft ein kürzerer Clip oder eine geringere Auflösung.',
                'credits' => "Dein {$mediaLabelDe} konnte gerade nicht erstellt werden, da das Guthaben für den Dienst aufgebraucht ist. "
                    .'Bitte versuche es später erneut oder wende dich an den Support.',
                'auth' => "Dein {$mediaLabelDe} konnte nicht erstellt werden, weil der Generierungsdienst derzeit nicht korrekt eingerichtet ist. "
                    .'Bitte versuche es später erneut oder wende dich an den Support.',
                'rate_limit' => "Der Dienst ist gerade stark ausgelastet, daher konnte dein {$mediaLabelDe} nicht erstellt werden. "
                    .'Bitte warte einen Moment und versuche es erneut.',
                'model' => "Dein {$mediaLabelDe} konnte nicht erstellt werden, da das gewählte Modell nicht verfügbar ist. "
                    .'Bitte wähle in den Einstellungen ein anderes Modell und versuche es erneut.',
                default => null,
            };
        }

        return match ($category) {
            'image_access' => 'We couldn\'t open the image you linked. '
                .'Please make sure the link points directly to a publicly accessible image file '
                .'(ending in .jpg, .jpeg, .png, .webp or .gif) — not to a web page — and try again. '
                .'You can also upload the image directly in the chat instead.',
            'timeout' => "Your {$mediaLabelEn} took too long to create and was stopped. "
                .'Please try again — for videos, a shorter clip or lower resolution often helps.',
            'credits' => "Your {$mediaLabelEn} couldn't be created right now because the generation service is out of credits. "
                .'Please try again later or contact support.',
            'auth' => "Your {$mediaLabelEn} couldn't be created because the generation service isn't set up correctly at the moment. "
                .'Please try again later or contact support.',
            'rate_limit' => "The service is very busy right now, so your {$mediaLabelEn} couldn't be created. "
                .'Please wait a moment and try again.',
            'model' => "Your {$mediaLabelEn} couldn't be created because the selected model isn't available. "
                .'Please pick a different model in Settings and try again.',
            default => null,
        };
    }

    private function buildContentBlockedMessage(string $providerName, string $reason, ?string $textResponse, string $mediaType, string $lang): string
    {
        $reasonExplanations = $this->getBlockReasonExplanations($lang);
        $explanation = $reasonExplanations[$reason] ?? $reasonExplanations['OTHER'];

        $displayName = ucfirst($providerName);

        if ('de' === $lang) {
            $mediaLabel = match ($mediaType) {
                'audio' => 'des Audios',
                'video' => 'des Videos',
                default => 'des Bildes',
            };
            $msg = "{$displayName} hat die Erstellung {$mediaLabel} mit dem Code **{$reason}** abgelehnt.\n\n{$explanation}";
        } else {
            $mediaLabel = match ($mediaType) {
                'audio' => 'audio',
                'video' => 'video',
                default => 'image',
            };
            $msg = "{$displayName} refused to generate the {$mediaLabel} with code **{$reason}**.\n\n{$explanation}";
        }

        if ($textResponse) {
            $preview = mb_substr($textResponse, 0, 300);
            $msg .= "\n\n> ".str_replace("\n", "\n> ", $preview);
        }

        return $msg;
    }

    /**
     * @return array<string, string>
     */
    private function getBlockReasonExplanations(string $lang): array
    {
        if ('de' === $lang) {
            return [
                'SAFETY' => 'Das bedeutet, dass der Inhalt gegen die Sicherheitsrichtlinien des Anbieters verstößt. '
                    .'Häufige Gründe: Darstellung realer Personen, Gewalt, anstößige Inhalte, '
                    .'oder Manipulation von Fotos echter Menschen. '
                    .'Tipp: Formuliere die Anfrage um oder verwende ein anderes Modell (z.B. GPT Image).',
                'RECITATION' => 'Das bedeutet, dass die Antwort urheberrechtlich geschütztes Material enthalten könnte. '
                    .'Der Anbieter blockiert Inhalte, die zu stark an bestehende Werke erinnern. '
                    .'Tipp: Formuliere die Anfrage origineller oder beschreibe den gewünschten Stil allgemeiner.',
                'PROHIBITED_CONTENT' => 'Der Inhalt wurde als verboten eingestuft. '
                    .'Der Anbieter blockiert bestimmte Kategorien grundsätzlich und ohne Ausnahme. '
                    .'Bitte überarbeite deine Anfrage grundlegend.',
                'BLOCKLIST' => 'Die Anfrage enthält Begriffe, die auf der Sperrliste des Anbieters stehen. '
                    .'Tipp: Verwende andere Begriffe oder formuliere die Anfrage um.',
                'SPII' => 'Die Anfrage scheint sensible persönliche Daten zu enthalten '
                    .'(z.B. Ausweisnummern, Finanzdaten). Der Anbieter blockiert solche Anfragen automatisch.',
                'IMAGE_SAFETY' => 'Eines der hochgeladenen Bilder wurde vom Anbieter als problematisch eingestuft. '
                    .'Tipp: Verwende ein anderes Bild oder ein anderes Modell.',
                'OTHER' => 'Die Anfrage wurde aus einem unbekannten Grund blockiert. '
                    .'Tipp: Formuliere die Anfrage um oder verwende ein anderes Modell.',
            ];
        }

        return [
            'SAFETY' => 'This means the content violates the provider\'s safety policies. '
                .'Common reasons: depicting real people, violence, offensive content, '
                .'or manipulating photos of real individuals. '
                .'Tip: Rephrase your request or try a different model (e.g. GPT Image).',
            'RECITATION' => 'This means the response may contain copyrighted material. '
                .'The provider blocks content that closely resembles existing works. '
                .'Tip: Make your request more original or describe the desired style more generally.',
            'PROHIBITED_CONTENT' => 'The content was classified as prohibited. '
                .'The provider blocks certain categories unconditionally. '
                .'Please fundamentally rework your request.',
            'BLOCKLIST' => 'Your request contains terms on the provider\'s blocklist. '
                .'Tip: Use different terms or rephrase your request.',
            'SPII' => 'Your request appears to contain sensitive personal information '
                .'(e.g. ID numbers, financial data). The provider blocks such requests automatically.',
            'IMAGE_SAFETY' => 'One of the uploaded images was flagged as problematic by the provider. '
                .'Tip: Try a different image or use a different model.',
            'OTHER' => 'The request was blocked for an unknown reason. '
                .'Tip: Rephrase your request or try a different model.',
        ];
    }

    private function getGenericMediaError(string $mediaType, string $lang): string
    {
        if ('de' === $lang) {
            return match ($mediaType) {
                'audio' => 'Das Audio konnte leider nicht erstellt werden. Bitte versuche es erneut oder wähle ein anderes Modell. Tipp: Verwende für Audio eine klare Anweisung wie "Lies diesen Text vor: ...".',
                'video' => 'Das Video konnte leider nicht erstellt werden. Bitte versuche es erneut oder wähle ein anderes Modell.',
                default => 'Das Bild konnte leider nicht erstellt werden. Bitte versuche es erneut oder wähle ein anderes Modell.',
            };
        }

        return match ($mediaType) {
            'audio' => 'Sorry, the audio could not be generated right now. Please try again or use a different model. Tip: For audio, try a clear prompt like "Read this text aloud: ...".',
            'video' => 'Sorry, the video could not be generated right now. Please try again or use a different model.',
            default => 'Sorry, the image could not be generated right now. Please try again or use a different model.',
        };
    }
}
